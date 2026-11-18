<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Support\ConcurrentProcessRunner;
use Tests\Integration\Support\IntegrationHttpClient;

final class RefundConcurrencyTest extends IntegrationTestCase
{
    private ConcurrentProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ConcurrentProcessRunner;

        // HTTP executes in PHP-FPM, not in these CLI actors. Both services use
        // the shared integration environment and the refund default is success.
        // Fail rather than silently exercise a decline or timeout configuration.
        $this->assertSame('success', config('services.simulated_payment.refund_force_outcome'));
        $this->assertFalse(config('services.simulated_payment.timeout_after_persist'));
    }

    #[Test]
    public function parallel_refunds_never_exceed_the_paid_total(): void
    {
        $order = $this->paidOrder();
        $actors = [];
        for ($index = 0; $index < 10; $index++) {
            $actors[] = $this->createActor($order->id, [
                'amount' => '30.00',
                'reason' => 'Concurrent refund cap proof',
                'idempotency_key' => "refund-cap-{$index}",
                'mode' => $index % 2 === 0 ? 'auto' : 'needs_approval',
            ]);
        }

        $results = $this->runner->run($actors);
        $statuses = array_column($results, 'status');
        $report = json_encode($results, JSON_THROW_ON_ERROR);
        $this->assertCount(10, $statuses, $report);
        $this->assertSame(3, count(array_filter($statuses, fn (int $status): bool => $status === 201)), $report);
        $this->assertSame(7, count(array_filter($statuses, fn (int $status): bool => $status === 422)), $report);
        foreach ($results as $result) {
            if ($result['status'] === 422) {
                $this->assertSame('REFUND_EXCEEDS_PAID', $result['body']['error_code'], $report);
            } else {
                $this->assertSame(201, $result['status'], $report);
                $this->assertContains($result['body']['data']['status'], ['PENDING_APPROVAL', 'SUCCEEDED'], $report);
            }
        }
        $this->assertSame(3, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame('90.00', RefundAmounts::reserved(Refund::query()->where('order_id', $order->id)->get()));

        $this->assertRefundInvariants();
    }

    #[Test]
    public function parallel_same_key_creates_produce_one_refund(): void
    {
        $order = $this->paidOrder();
        $actor = $this->createActor($order->id, [
            'amount' => '30.00',
            'reason' => 'Concurrent identical refund proof',
            'idempotency_key' => 'refund-same-key',
            'mode' => 'needs_approval',
        ]);
        $results = $this->runner->run(array_fill(0, 5, $actor));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $report = json_encode($results, JSON_THROW_ON_ERROR);
        $this->assertSame([200, 200, 200, 200, 201], $statuses, $report);
        $this->assertSame(1, Refund::query()->count());
        $refund = Refund::query()->sole();
        foreach ($results as $result) {
            $this->assertSame($refund->id, $result['body']['data']['id'], $report);
        }
        $this->assertSame(RefundStatus::PENDING_APPROVAL, $refund->status);
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());

        $this->assertRefundInvariants();
    }

    #[Test]
    public function parallel_first_refunds_on_distinct_orders_never_fail(): void
    {
        $orders = [];
        $actors = [];
        for ($index = 0; $index < 8; $index++) {
            $order = $this->paidOrder();
            $orders[] = $order;
            $actors[] = $this->createActor($order->id, [
                'amount' => '10.00',
                'reason' => 'Concurrent first refunds on distinct orders',
                'idempotency_key' => "refund-distinct-order-{$index}",
                'mode' => 'needs_approval',
            ]);
        }
        $this->assertSame(0, Refund::query()->count());

        $results = $this->runner->run($actors);
        $report = json_encode($results, JSON_THROW_ON_ERROR);
        $this->assertCount(8, $results, $report);
        foreach ($results as $result) {
            $this->assertSame(201, $result['status'] ?? null, $report);
            $this->assertSame('10.00', $result['body']['data']['amount'], $report);
        }
        $this->assertSame(8, Refund::query()->count());
        foreach ($orders as $order) {
            $refund = Refund::query()->where('order_id', $order->id)->sole();
            $this->assertSame('10.00', $refund->amount);
        }

        $this->assertRefundInvariants();
    }

    #[Test]
    public function same_key_on_two_orders_yields_one_refund_and_one_conflict(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        $body = [
            'amount' => '10.00',
            'reason' => 'Concurrent key reuse on distinct orders',
            'idempotency_key' => 'refund-two-orders-same-key',
            'mode' => 'needs_approval',
        ];
        $results = $this->runner->run([
            $this->createActor($first->id, $body),
            $this->createActor($second->id, $body),
        ]);
        $report = json_encode($results, JSON_THROW_ON_ERROR);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, $report);
        $this->assertSame(1, Refund::query()->count());
        $refund = Refund::query()->sole();
        foreach ($results as $result) {
            if ($result['status'] === 409) {
                $this->assertSame('IDEMPOTENCY_CONFLICT', $result['body']['error_code'], $report);
                $this->assertArrayNotHasKey('data', $result['body'], $report);
            } else {
                $this->assertSame($refund->id, $result['body']['data']['id'], $report);
                $this->assertSame($refund->order_id, $result['body']['data']['order_id'], $report);
            }
        }

        $this->assertRefundInvariants();
    }

    #[Test]
    public function parallel_double_approval_executes_once(): void
    {
        $order = $this->paidOrder();
        $response = IntegrationHttpClient::request('POST', "/api/agent/orders/{$order->id}/refunds", [
            'amount' => '100.00',
            'reason' => 'Concurrent approval proof',
            'idempotency_key' => 'refund-double-approval',
            'mode' => 'needs_approval',
        ], $this->headers());
        $this->assertSame(201, $response['status']);
        $this->assertSame('PENDING_APPROVAL', $response['body']['data']['status']);
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());
        $refundId = $response['body']['data']['id'];

        $results = $this->runner->run(array_fill(0, 5, [
            'action' => 'refund-approve',
            'payload' => [
                'refund_id' => $refundId,
                'body' => ['decided_by' => 'integration-ops'],
                'headers' => $this->headers(),
            ],
        ]));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $report = json_encode($results, JSON_THROW_ON_ERROR);
        $this->assertSame([200, 409, 409, 409, 409], $statuses, $report);
        foreach ($results as $result) {
            if ($result['status'] === 409) {
                $this->assertSame('REFUND_NOT_PENDING_APPROVAL', $result['body']['error_code'], $report);
            } else {
                $this->assertSame($refundId, $result['body']['data']['id'], $report);
                $this->assertSame('SUCCEEDED', $result['body']['data']['status'], $report);
            }
        }
        $refund = Refund::query()->findOrFail($refundId);
        $this->assertSame(RefundStatus::SUCCEEDED, $refund->status);
        $this->assertSame('integration-ops', $refund->decided_by);
        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(1, DB::table('simulated_payment_operations')->count());
        $operation = DB::table('simulated_payment_operations')->sole();
        $this->assertSame('refund', $operation->operation);
        $this->assertSame($order->id, (int) $operation->order_id);
        $this->assertSame($refund->provider_idempotency_key, $operation->idempotency_key);
        $this->assertSame(1, (int) $operation->request_count);
        $this->assertSame(1, (int) $operation->side_effect_count);

        $this->assertRefundInvariants();
    }

    private function paidOrder(): Order
    {
        $product = Product::query()->create([
            'name' => 'Refund concurrency fixture',
            'price' => '100.00',
            'stock' => 0,
        ]);
        $order = Order::query()->create([
            'user_id' => 1,
            'status' => OrderStatus::PAID,
            'total_amount' => '100.00',
            'idempotency_key' => 'refund-order-'.Str::uuid(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => '100.00',
        ]);

        return $order;
    }

    private function createActor(int $orderId, array $body): array
    {
        return [
            'action' => 'refund-create',
            'payload' => [
                'order_id' => $orderId,
                'body' => $body,
                'headers' => $this->headers(),
            ],
        ];
    }

    private function headers(): array
    {
        return [
            'X-Service-Token' => 'integration-agent-token-not-secret',
            'X-Customer-Id' => '1',
        ];
    }

    private function assertRefundInvariants(): void
    {
        foreach (DB::table('orders')->get(['id', 'total_amount']) as $order) {
            $rows = DB::table('refunds')->where('order_id', $order->id)->get();
            $reserved = RefundAmounts::reserved($rows);
            $this->assertTrue(
                bccomp($reserved, (string) $order->total_amount, 2) <= 0,
                "Order {$order->id} reserved {$reserved} exceeds paid total {$order->total_amount}."
            );
        }
    }
}
