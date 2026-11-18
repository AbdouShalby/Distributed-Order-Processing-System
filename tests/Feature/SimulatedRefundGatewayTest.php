<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Exceptions\PaymentOutcomeUnknownException;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SimulatedRefundGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.simulated_payment.force_outcome' => 'success',
            'services.simulated_payment.refund_force_outcome' => 'success',
            'services.simulated_payment.fail_before_persist' => false,
            'services.simulated_payment.timeout_after_persist' => false,
            'services.simulated_payment.after_persist_barrier' => null,
        ]);
    }

    #[Test]
    public function refund_is_recorded_as_refund_and_replays_without_second_side_effect(): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);

        $first = $gateway->refund($order->id, '30.00', 'refund-replay');
        config(['services.simulated_payment.refund_force_outcome' => 'decline']);
        $second = $gateway->refund($order->id, '30.00', 'refund-replay');

        $this->assertTrue($first->isSuccessful());
        $this->assertTrue($second->isSuccessful());
        $this->assertSame($first->getMessage(), $second->getMessage());
        $this->assertDatabaseCount('simulated_payment_operations', 1);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $order->id,
            'idempotency_key' => 'refund-replay',
            'operation' => 'refund',
            'status' => 'SUCCEEDED',
            'side_effect_count' => 1,
            'request_count' => 2,
        ]);
    }

    #[Test]
    public function a_charge_key_cannot_be_reused_for_a_refund(): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);
        $gateway->charge($order->id, '30.00', 'charge-key');

        $this->expectException(\DomainException::class);
        $gateway->refund($order->id, '30.00', 'charge-key');
    }

    #[Test]
    public function a_refund_key_cannot_be_reused_for_a_charge(): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);
        $gateway->refund($order->id, '30.00', 'refund-key');

        $this->expectException(\DomainException::class);
        $gateway->charge($order->id, '30.00', 'refund-key');
    }

    #[Test]
    #[DataProvider('mismatchedRefunds')]
    public function a_refund_key_cannot_be_reused_for_a_different_request(bool $differentOrder): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);
        $gateway->refund($order->id, '30.00', 'refund-mismatch');
        $orderId = $differentOrder ? $this->createOrder()->id : $order->id;

        $this->expectException(\DomainException::class);
        $gateway->refund($orderId, $differentOrder ? '30.00' : '30.01', 'refund-mismatch');
    }

    public static function mismatchedRefunds(): array
    {
        return [[true], [false]];
    }

    #[Test]
    public function forced_refund_decline_is_not_successful(): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);
        config(['services.simulated_payment.refund_force_outcome' => 'decline']);

        $first = $gateway->refund($order->id, '30.00', 'refund-decline');
        config(['services.simulated_payment.refund_force_outcome' => 'success']);
        $second = $gateway->refund($order->id, '30.00', 'refund-decline');

        $this->assertFalse($first->isSuccessful());
        $this->assertFalse($second->isSuccessful());
        $this->assertSame($first->getMessage(), $second->getMessage());
        $this->assertDatabaseHas('simulated_payment_operations', [
            'operation' => 'refund',
            'status' => 'DECLINED',
            'side_effect_count' => 1,
            'request_count' => 2,
        ]);
    }

    #[Test]
    #[DataProvider('invalidOutcomes')]
    public function rejects_unsupported_refund_outcomes_before_persisting(?string $outcome): void
    {
        $order = $this->createOrder();
        config(['services.simulated_payment.refund_force_outcome' => $outcome]);

        try {
            app(PaymentGatewayInterface::class)->refund($order->id, '30.00', 'invalid-outcome');
            $this->fail('Expected an invalid refund outcome to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('simulated_payment_operations', 0);
        }
    }

    public static function invalidOutcomes(): array
    {
        return [[null], [''], ['random']];
    }

    #[Test]
    public function refund_ignores_the_charge_failure_before_persist_switch(): void
    {
        $order = $this->createOrder();
        config(['services.simulated_payment.fail_before_persist' => true]);

        $this->assertTrue(app(PaymentGatewayInterface::class)
            ->refund($order->id, '30.00', 'refund-available')->isSuccessful());
        $this->assertDatabaseCount('simulated_payment_operations', 1);
    }

    #[Test]
    public function refund_timeout_leaves_a_durable_result_and_replay_does_not_time_out_again(): void
    {
        $order = $this->createOrder();
        $gateway = app(PaymentGatewayInterface::class);
        config(['services.simulated_payment.timeout_after_persist' => true]);

        try {
            $gateway->refund($order->id, '30.00', 'refund-timeout');
            $this->fail('Expected an ambiguous provider timeout.');
        } catch (PaymentOutcomeUnknownException) {
            $this->assertDatabaseHas('simulated_payment_operations', [
                'idempotency_key' => 'refund-timeout',
                'operation' => 'refund',
                'status' => 'SUCCEEDED',
                'request_count' => 1,
                'side_effect_count' => 1,
            ]);
        }

        $this->assertTrue($gateway->refund($order->id, '30.00', 'refund-timeout')->isSuccessful());
        $this->assertDatabaseHas('simulated_payment_operations', [
            'idempotency_key' => 'refund-timeout',
            'request_count' => 2,
            'side_effect_count' => 1,
        ]);
    }

    #[Test]
    public function migrations_round_trip_on_sqlite_and_existing_operations_default_to_charge(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $order = $this->createOrder();
        $refundMigration = require database_path('migrations/2026_11_18_000001_create_refunds_table.php');
        $operationMigration = require database_path('migrations/2026_11_18_000002_add_operation_to_simulated_payment_operations.php');

        $operationMigration->down();
        $refundMigration->down();
        $this->assertFalse(Schema::hasColumn('simulated_payment_operations', 'operation'));
        $this->assertFalse(Schema::hasTable('refunds'));
        DB::table('simulated_payment_operations')->insert([
            'idempotency_key' => 'legacy-charge',
            'order_id' => $order->id,
            'amount' => '30.00',
            'status' => 'SUCCEEDED',
            'message' => 'Payment processed successfully.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $refundMigration->up();
        $operationMigration->up();
        $this->assertTrue(Schema::hasTable('refunds'));
        $this->assertDatabaseHas('simulated_payment_operations', [
            'idempotency_key' => 'legacy-charge',
            'operation' => 'charge',
        ]);
        $this->assertTrue(app(PaymentGatewayInterface::class)
            ->charge($order->id, '30.00', 'legacy-charge')->isSuccessful());
        $this->assertDatabaseCount('simulated_payment_operations', 1);
    }

    #[Test]
    public function repository_persists_casts_locks_and_saves_refunds(): void
    {
        $order = $this->createOrder();
        $repository = app(RefundRepositoryInterface::class);
        $this->assertNull($repository->findByKey('missing'));
        $this->assertNull($repository->lockById(999));
        $refund = $repository->create($this->refundAttributes($order, 'stored-refund') + [
            'trace_id' => 'refund-trace',
            'decided_by' => 'operator',
            'decision_reason' => 'Approved after review.',
            'decided_at' => new \DateTimeImmutable('2026-11-18T10:00:00+00:00'),
        ]);

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame('30.00', $refund->amount);
        $this->assertSame(RefundStatus::PENDING_APPROVAL, $refund->status);
        $this->assertInstanceOf(\DateTimeInterface::class, $refund->decided_at);
        $this->assertSame($refund->id, $repository->findByKey('stored-refund')->id);
        DB::transaction(function () use ($repository, $order, $refund): void {
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertSame([$refund->id], array_map(
                static fn (Refund $row): int => $row->id,
                $repository->forOrderLocked($order->id)
            ));
            $locked = $repository->lockById($refund->id);
            $locked->status = RefundStatus::FAILED;
            $locked->failure_message = 'Provider declined the refund.';
            $repository->save($locked);
        });
        $this->assertSame(RefundStatus::FAILED, $refund->fresh()->status);
        $this->assertSame('Provider declined the refund.', $refund->fresh()->failure_message);
        $order->delete();
        $this->assertDatabaseCount('refunds', 0);
    }

    #[Test]
    public function repository_filters_and_orders_approval_and_customer_queries(): void
    {
        $order = $this->createOrder();
        $otherOrder = $this->createOrder();
        $repository = app(RefundRepositoryInterface::class);
        $old = $repository->create($this->refundAttributes($order, 'old'));
        $old->forceFill(['created_at' => '2026-11-16 10:00:00'])->save();
        $boundary = $repository->create($this->refundAttributes($order, 'boundary'));
        $boundary->forceFill(['created_at' => '2026-11-17 10:00:00'])->save();
        $recent = $repository->create(array_replace($this->refundAttributes($order, 'recent'), [
            'status' => RefundStatus::SUCCEEDED,
        ]));
        $recent->forceFill(['created_at' => '2026-11-18 10:00:00'])->save();
        $other = $repository->create(array_replace($this->refundAttributes($otherOrder, 'other'), [
            'status' => RefundStatus::REJECTED,
        ]));
        $other->forceFill(['created_at' => '2026-11-18 11:00:00'])->save();
        $ids = static fn (array $rows): array => array_map(static fn (Refund $refund): int => $refund->id, $rows);

        $this->assertSame([$old->id, $boundary->id], $ids($repository->pendingApproval(50)));
        $this->assertSame([$old->id], $ids($repository->pendingApproval(1)));
        $this->assertSame([$recent->id, $boundary->id], $ids($repository->forCustomerSince(
            $order->user_id,
            new \DateTimeImmutable('2026-11-17T10:00:00+00:00')
        )));
    }

    private function createOrder(): Order
    {
        return Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => 'PAID',
            'total_amount' => '100.00',
            'idempotency_key' => 'order-'.bin2hex(random_bytes(8)),
        ]);
    }

    private function refundAttributes(Order $order, string $key): array
    {
        return [
            'order_id' => $order->id,
            'customer_id' => $order->user_id,
            'amount' => '30.00',
            'reason' => 'Customer requested a partial refund.',
            'mode' => 'needs_approval',
            'status' => RefundStatus::PENDING_APPROVAL,
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', $key),
            'provider_idempotency_key' => 'provider-'.$key,
        ];
    }
}
