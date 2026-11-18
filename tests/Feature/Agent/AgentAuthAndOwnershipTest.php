<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Application\DTOs\OrderResponseDTO;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order as OrderEntity;
use App\Domain\Refund\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentAuthAndOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->seed();
        config([
            'services.agent.token' => 'test-token-not-secret',
            'services.agent.rate_limit_per_minute' => 600,
        ]);
    }

    #[Test]
    public function missing_or_wrong_token_is_401(): void
    {
        foreach ([[], ['X-Service-Token' => 'wrong-token-not-secret']] as $headers) {
            $this->getJson('/api/agent/orders', $headers)->assertUnauthorized()->assertExactJson([
                'message' => 'Unauthorized.',
                'error_code' => 'UNAUTHORIZED',
            ]);
        }
    }

    #[Test]
    public function empty_or_null_token_config_rejects_even_an_empty_header(): void
    {
        foreach (['', null] as $token) {
            config(['services.agent.token' => $token]);

            foreach (['', 'test-token-not-secret'] as $provided) {
                $this->getJson('/api/agent/orders', ['X-Service-Token' => $provided])
                    ->assertUnauthorized()->assertExactJson([
                        'message' => 'Unauthorized.',
                        'error_code' => 'UNAUTHORIZED',
                    ]);
            }
        }
    }

    #[Test]
    public function unauthorized_requests_do_not_consume_agent_quota(): void
    {
        config(['services.agent.rate_limit_per_minute' => 1]);

        $this->getJson('/api/agent/orders', ['X-Service-Token' => 'wrong-token-not-secret'])->assertUnauthorized();
        $this->getJson('/api/agent/orders', ['X-Service-Token' => 'wrong-token-not-secret'])->assertUnauthorized();
        $this->getJson('/api/agent/orders', $this->headers())->assertOk();
        $this->getJson('/api/agent/orders', $this->headers())->assertTooManyRequests();
        config(['services.agent.token' => '']);
        $this->getJson('/api/agent/orders', $this->headers())->assertUnauthorized();
    }

    #[Test]
    public function other_customers_order_is_404_identical_to_missing(): void
    {
        $order = $this->createOrder(2);
        $otherCustomer = $this->getJson('/api/agent/orders/'.$order->id, $this->headers());
        $missing = $this->getJson('/api/agent/orders/999999', $this->headers());

        $otherCustomer->assertNotFound()->assertExactJson([
            'message' => 'Order not found.',
            'error_code' => 'NOT_FOUND',
        ]);
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $otherCustomer->getContent());
    }

    #[Test]
    #[DataProvider('invalidCustomerHeaders')]
    public function invalid_customer_header_is_422(?string $customerId): void
    {
        $headers = ['X-Service-Token' => 'test-token-not-secret'];
        if ($customerId !== null) {
            $headers['X-Customer-Id'] = $customerId;
        }

        foreach (['/api/agent/orders', '/api/agent/orders/999999'] as $url) {
            $this->getJson($url, $headers)->assertUnprocessable()
                ->assertJsonPath('error_code', 'VALIDATION_ERROR')
                ->assertJsonValidationErrors('X-Customer-Id');
        }
    }

    public static function invalidCustomerHeaders(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'zero' => ['0'],
            'letters' => ['abc'],
            'leading space' => [' 1'],
            'trailing space' => ['1 '],
            'leading zero' => ['01'],
            'negative' => ['-1'],
            'decimal' => ['1.0'],
            'newline' => ["1\n"],
        ];
    }

    #[Test]
    public function list_is_customer_scoped_newest_first_limited_and_includes_refund_amounts(): void
    {
        $orders = [];
        for ($i = 0; $i < 22; $i++) {
            $orders[] = $this->createOrder(1, ['created_at' => now()->subMinutes($i)]);
        }
        $foreign = $this->createOrder(2, ['created_at' => now()->addMinute()]);
        $this->createRefund($foreign, RefundStatus::SUCCEEDED, '99.00');
        $this->createRefund($orders[0], RefundStatus::SUCCEEDED, '10.00');
        $this->createRefund($orders[0], RefundStatus::PENDING, '5.00');
        $this->createRefund($orders[0], RefundStatus::FAILED, '7.00');
        $this->createRefund($orders[1], RefundStatus::PENDING_APPROVAL, '3.25');
        $this->createRefund($orders[1], RefundStatus::REJECTED, '8.00');

        $response = $this->getJson('/api/agent/orders', $this->headers())->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.refunded_amount', '10.00')
            ->assertJsonPath('data.0.reserved_amount', '15.00')
            ->assertJsonPath('data.1.refunded_amount', '0.00')
            ->assertJsonPath('data.1.reserved_amount', '3.25')
            ->assertJsonPath('data.2.refunded_amount', '0.00')
            ->assertJsonPath('data.2.reserved_amount', '0.00');

        $this->assertSame(array_map(fn (Order $order) => $order->id, array_slice($orders, 0, 20)),
            array_column($response->json('data'), 'id'));
        $this->assertSame([1], array_values(array_unique(array_column($response->json('data'), 'user_id'))));
    }

    #[Test]
    public function show_preserves_dto_fields_and_includes_refund_amounts_and_trace(): void
    {
        $order = $this->createOrder(1);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => 1, 'quantity' => 2,
            'unit_price' => '50.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createRefund($order, RefundStatus::SUCCEEDED, '10.00');
        $this->createRefund($order, RefundStatus::PENDING, '5.00');
        $this->createRefund($order, RefundStatus::PENDING_APPROVAL, '2.25');
        $this->createRefund($order, RefundStatus::FAILED, '7.00');
        $this->createRefund($order, RefundStatus::REJECTED, '8.00');
        $expected = OrderResponseDTO::fromEntity(app(OrderRepositoryInterface::class)->findById($order->id))->toArray();

        $this->getJson('/api/agent/orders/'.$order->id, $this->headers() + ['X-Trace-Id' => 'agent-read-trace'])
            ->assertOk()->assertHeader('X-Trace-Id', 'agent-read-trace')
            ->assertExactJson(['data' => $expected + ['refunded_amount' => '10.00', 'reserved_amount' => '17.25']]);
    }

    #[Test]
    public function empty_customer_list_is_empty(): void
    {
        $this->createOrder(2);
        $this->getJson('/api/agent/orders', $this->headers())->assertOk()->assertExactJson(['data' => []]);
    }

    #[Test]
    public function agent_is_not_limited_at_sixty_but_public_routes_still_are(): void
    {
        $this->freezeTime();
        $order = $this->createOrder(1);

        for ($i = 0; $i < 61; $i++) {
            $this->getJson('/api/agent/orders', $this->headers())->assertOk()->assertHeader('X-RateLimit-Limit', '600');
        }
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/orders/'.$order->id)->assertOk()->assertHeader('X-RateLimit-Limit', '60');
        }
        $this->getJson('/api/orders/'.$order->id)->assertTooManyRequests();
    }

    #[Test]
    public function agent_limit_is_configurable_shared_across_ips_and_keyed_by_token_hash(): void
    {
        config(['services.agent.rate_limit_per_minute' => 2]);
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->getJson('/api/agent/orders', $this->headers())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->getJson('/api/agent/orders', $this->headers())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.3'])
            ->getJson('/api/agent/orders', $this->headers())->assertTooManyRequests();

        config(['services.agent.token' => 'rotated-test-token-not-secret']);
        $headers = ['X-Service-Token' => 'rotated-test-token-not-secret', 'X-Customer-Id' => '1'];
        $this->getJson('/api/agent/orders', $headers)->assertOk();
    }

    #[Test]
    public function public_order_show_without_token_is_unchanged(): void
    {
        $order = $this->createOrder(1);
        $expected = OrderResponseDTO::fromEntity(app(OrderRepositoryInterface::class)->findById($order->id))->toArray();

        $this->getJson('/api/orders/'.$order->id)->assertOk()->assertExactJson(['data' => $expected]);
    }

    #[Test]
    public function repository_customer_lock_returns_domain_order_only_for_owner_and_queries_order_first(): void
    {
        $order = $this->createOrder(1);
        $repository = app(OrderRepositoryInterface::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $locked = DB::transaction(fn () => $repository->lockForCustomer($order->id, 1));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertInstanceOf(OrderEntity::class, $locked);
        $this->assertSame($order->id, $locked->getId());
        $this->assertStringContainsString('from "orders"', $queries[0]['query']);
        $this->assertStringContainsString('"user_id" = ?', $queries[0]['query']);
        $this->assertNull(DB::transaction(fn () => $repository->lockForCustomer($order->id, 2)));
        $this->assertNull(DB::transaction(fn () => $repository->lockForCustomer(999999, 1)));
    }

    private function headers(): array
    {
        return ['X-Service-Token' => 'test-token-not-secret', 'X-Customer-Id' => '1'];
    }

    private function createOrder(int $customerId, array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill($attributes + [
            'user_id' => $customerId,
            'status' => 'PAID',
            'total_amount' => '100.00',
            'idempotency_key' => 'agent-order-'.fake()->uuid(),
        ]);
        $order->save();

        return $order;
    }

    private function createRefund(Order $order, RefundStatus $status, string $amount): void
    {
        $key = fake()->uuid();
        Refund::query()->create([
            'order_id' => $order->id,
            'customer_id' => $order->user_id,
            'amount' => $amount,
            'reason' => 'Agent read test',
            'mode' => 'auto',
            'status' => $status,
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', $key),
            'provider_idempotency_key' => 'refund-'.$key,
        ]);
    }
}
