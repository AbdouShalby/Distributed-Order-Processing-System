<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Refunds\RefundFingerprint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AgentEvalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AgentEvalSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
        config([
            'services.agent.token' => 'agent-eval-fake-token-not-secret',
            'services.agent.rate_limit_per_minute' => 600,
            'services.refunds.auto_approve_limit' => '50.00',
            'services.simulated_payment.refund_force_outcome' => 'success',
            'services.simulated_payment.fail_before_persist' => false,
            'services.simulated_payment.timeout_after_persist' => false,
            'services.simulated_payment.after_persist_barrier' => null,
        ]);
    }

    #[Test]
    public function seeded_rows_match_the_source_json_without_payment_operations(): void
    {
        $seed = $this->sourceSeed();
        $this->seed(AgentEvalSeeder::class);

        $this->assertDatabaseCount('users', count($seed['customers']));
        foreach ($seed['customers'] as $customer) {
            $this->assertDatabaseHas('users', $customer + [
                'email' => 'customer-'.chr(96 + $customer['id']).'@example.test',
            ]);
        }

        $products = [];
        $itemCount = 0;
        $refundCount = 0;
        $this->assertDatabaseCount('orders', count($seed['orders']));
        foreach ($seed['orders'] as $expected) {
            $order = Order::query()->findOrFail($expected['id']);
            $timestamp = now()->subDays($expected['created_days_ago'])->toDateTimeString();
            $this->assertSame($expected['user_id'], $order->user_id);
            $this->assertSame($expected['status'], $order->status->value);
            $this->assertSame($expected['total_amount'], $order->total_amount);
            $this->assertSame('agent-eval-order-'.$expected['id'], $order->idempotency_key);
            $this->assertSame($timestamp, $order->created_at->toDateTimeString());
            $this->assertSame($timestamp, $order->updated_at->toDateTimeString());
            $this->assertSame($expected['items'], $order->items()->orderBy('id')->get()->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->all());
            $itemCount += count($expected['items']);

            foreach ($expected['items'] as $item) {
                $products[$item['product_id']] ??= $item['unit_price'];
            }

            $refunds = Refund::query()->where('order_id', $expected['id'])->orderBy('id')->get();
            $this->assertCount(count($expected['refunds'] ?? []), $refunds);
            foreach ($expected['refunds'] ?? [] as $index => $refund) {
                $actual = $refunds[$index];
                $suffix = $expected['id'].'-'.($index + 1);
                foreach (['amount', 'mode', 'reason', 'decided_by'] as $field) {
                    $this->assertSame($refund[$field], $actual->$field);
                }
                $this->assertSame($refund['status'], $actual->status->value);
                $this->assertSame($expected['user_id'], $actual->customer_id);
                $this->assertSame('agent-eval-refund-'.$suffix, $actual->idempotency_key);
                $this->assertSame('agent-eval-provider-'.$suffix, $actual->provider_idempotency_key);
                $this->assertSame(RefundFingerprint::of($expected['id'], $expected['user_id'], $refund['amount'], $refund['mode']), $actual->request_fingerprint);
                $this->assertSame('t-0000000000000000', $actual->trace_id);
                $refundTimestamp = now()->subDays($refund['created_days_ago'])->toDateTimeString();
                $this->assertSame($refundTimestamp, $actual->created_at->toDateTimeString());
                $this->assertSame($refundTimestamp, $actual->updated_at->toDateTimeString());
                $refundCount++;
            }
        }

        $this->assertDatabaseCount('products', count($products));
        foreach ($products as $id => $price) {
            $product = Product::query()->findOrFail($id);
            $this->assertSame('Eval product '.$id, $product->name);
            $this->assertSame($price, $product->price);
            $this->assertSame(0, $product->stock);
        }
        $this->assertDatabaseCount('order_items', $itemCount);
        $this->assertDatabaseCount('refunds', $refundCount);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function agent_can_read_customer_scoped_seed_orders_and_refund_a_seeded_order(): void
    {
        $this->seed(AgentEvalSeeder::class);
        $seed = $this->sourceSeed();

        foreach ([1 => range(1001, 1008), 2 => [1009, 1010]] as $customerId => $ids) {
            $orders = $this->getJson('/api/agent/orders', $this->headers($customerId))
                ->assertOk()->assertJsonCount(count($ids), 'data')->json('data');
            $actualIds = array_column($orders, 'id');
            sort($actualIds);
            $this->assertSame($ids, $actualIds);
            $expectedOrders = array_column($seed['orders'], null, 'id');
            foreach ($orders as $order) {
                $this->assertSame($expectedOrders[$order['id']]['status'], $order['status']);
                $this->assertSame($expectedOrders[$order['id']]['total_amount'], $order['total_amount']);
            }
        }

        $this->getJson('/api/agent/orders/1007', $this->headers(1))->assertOk()
            ->assertJsonPath('data.refunded_amount', '30.00')->assertJsonPath('data.status', 'REFUNDED');
        $oldOrder = $this->getJson('/api/agent/orders/1008', $this->headers(1))->assertOk();
        $this->assertTrue(CarbonImmutable::parse($oldOrder->json('data.created_at'))->lessThan(now()->subDays(30)));

        $this->postJson('/api/agent/orders/1004/refunds', [
            'amount' => '10.00',
            'mode' => 'auto',
            'reason' => 'Verify seeded order refund compatibility.',
            'idempotency_key' => 'agent-eval-new-refund-1004',
        ], $this->headers(1))->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.amount', '10.00');
        $this->assertDatabaseHas('simulated_payment_operations', ['order_id' => 1004, 'operation' => 'refund', 'side_effect_count' => 1]);
    }

    #[Test]
    public function existing_users_and_products_are_preserved(): void
    {
        $user = User::factory()->create(['id' => 1, 'name' => 'Existing customer', 'email' => 'existing@example.test']);
        $product = Product::query()->forceCreate(['id' => 2, 'name' => 'Existing product', 'price' => '99.00', 'stock' => 7]);
        $userAttributes = $user->fresh()->getAttributes();
        $productAttributes = $product->fresh()->getAttributes();

        $this->seed(AgentEvalSeeder::class);

        $this->assertSame($userAttributes, $user->fresh()->getAttributes());
        $this->assertSame($productAttributes, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('orders', count($this->sourceSeed()['orders']));
    }

    private function sourceSeed(): array
    {
        return json_decode(file_get_contents(database_path('seeders/data/agent-eval-seed.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function headers(int $customerId): array
    {
        return ['X-Service-Token' => 'agent-eval-fake-token-not-secret', 'X-Customer-Id' => (string) $customerId];
    }
}
