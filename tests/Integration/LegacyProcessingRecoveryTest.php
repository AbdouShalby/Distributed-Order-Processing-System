<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\LegacyProcessingBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Support\ConcurrentProcessRunner;

final class LegacyProcessingRecoveryTest extends IntegrationTestCase
{
    private ConcurrentProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ConcurrentProcessRunner;
    }

    #[Test]
    public function upgrade_migration_classifies_a_pre_existing_processing_row(): void
    {
        // Roll back to just before the correctness-metadata migration, however
        // many later migrations exist, so the upgrade path is replayed in full.
        $steps = DB::table('migrations')
            ->where('migration', '>=', '2026_08_27_000001_add_correctness_metadata_to_orders_table')
            ->count();
        $rolledBack = Artisan::call('migrate:rollback', [
            '--step' => $steps,
            '--force' => true,
        ]);
        $this->assertSame(0, $rolledBack);
        $this->assertFalse(Schema::hasColumn('orders', 'processing_recovery_state'));

        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => 1,
            'status' => 'PROCESSING',
            'total_amount' => '999.99',
            'idempotency_key' => 'pre-migration-processing',
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migrated = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $migrated);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PROCESSING',
            'processing_token' => null,
            'processing_claimed_at' => null,
            'processing_lease_until' => null,
            'processing_attempt' => 0,
            'payment_idempotency_key' => null,
            'processing_recovery_state' => 'RECONCILIATION_REQUIRED',
        ]);
    }

    #[Test]
    public function backfill_classifies_legacy_and_current_processing_rows_idempotently(): void
    {
        $legacyOrderId = $this->insertOrder('PROCESSING', 'legacy-ambiguous');
        $currentToken = Str::uuid()->toString();
        $currentLease = now()->addMinute();
        $currentOrderId = $this->insertOrder('PROCESSING', 'current-active');
        $currentPaymentKey = "order-payment:v1:{$currentOrderId}";
        DB::table('orders')->where('id', $currentOrderId)->update([
            'processing_token' => $currentToken,
            'processing_claimed_at' => now(),
            'processing_lease_until' => $currentLease,
            'processing_attempt' => 1,
            'payment_idempotency_key' => $currentPaymentKey,
        ]);
        $terminalOrderId = $this->insertOrder('PAID', 'legacy-terminal');

        $first = app(LegacyProcessingBackfill::class)->run();
        $second = app(LegacyProcessingBackfill::class)->run();

        $this->assertSame([
            'leased' => 1,
            'reconciliation_required' => 1,
        ], $first);
        $this->assertSame([
            'leased' => 0,
            'reconciliation_required' => 0,
        ], $second);
        $this->assertSame(
            'RECONCILIATION_REQUIRED',
            DB::table('orders')->where('id', $legacyOrderId)->value('processing_recovery_state')
        );
        $this->assertDatabaseHas('orders', [
            'id' => $currentOrderId,
            'status' => 'PROCESSING',
            'processing_token' => $currentToken,
            'processing_attempt' => 1,
            'payment_idempotency_key' => $currentPaymentKey,
            'processing_recovery_state' => 'LEASED',
        ]);
        $this->assertSame(
            $currentLease->format('Y-m-d H:i:s'),
            DB::table('orders')
                ->where('id', $currentOrderId)
                ->value('processing_lease_until')
        );
        $this->assertNull(
            DB::table('orders')->where('id', $terminalOrderId)->value('processing_recovery_state')
        );
    }

    #[Test]
    public function ambiguous_legacy_processing_is_visible_but_never_automatically_charged(): void
    {
        $orderId = $this->insertOrder('PROCESSING', 'legacy-unknown');
        app(LegacyProcessingBackfill::class)->run();

        $exitCode = Artisan::call('orders:legacy-processing');
        $auditOutput = Artisan::output();
        $requeued = Artisan::call('orders:recover-expired');
        $process = $this->runner->run([[
            'action' => 'process',
            'payload' => ['order_id' => $orderId],
        ]]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString((string) $orderId, $auditOutput);
        $this->assertSame(0, $requeued);
        $this->assertSame('skipped', $process[0]['outcome']);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(
            'RECONCILIATION_REQUIRED',
            DB::table('orders')->where('id', $orderId)->value('processing_recovery_state')
        );
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());
        $this->assertSame(0, (int) Redis::llen('queues:default'));
    }

    #[Test]
    public function expired_current_lease_reuses_its_persisted_provider_identity(): void
    {
        $orderId = $this->insertOrder('PROCESSING', 'expired-current-identity');
        $persistedPaymentKey = "provider-operation:v0:{$orderId}";
        DB::table('orders')->where('id', $orderId)->update([
            'processing_token' => Str::uuid()->toString(),
            'processing_claimed_at' => now()->subMinutes(2),
            'processing_lease_until' => now()->subMinute(),
            'processing_attempt' => 3,
            'payment_idempotency_key' => $persistedPaymentKey,
            'processing_recovery_state' => 'LEASED',
        ]);

        $process = $this->runner->run([[
            'action' => 'process',
            'payload' => ['order_id' => $orderId],
        ]]);

        $this->assertSame('paid', $process[0]['outcome']);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PAID',
            'processing_attempt' => 4,
            'payment_idempotency_key' => $persistedPaymentKey,
        ]);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'idempotency_key' => $persistedPaymentKey,
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);
    }

    #[Test]
    public function operator_approved_legacy_work_recovers_with_one_new_payment_identity(): void
    {
        $orderId = $this->insertOrder('PROCESSING', 'legacy-confirmed-safe');
        app(LegacyProcessingBackfill::class)->run();

        $refused = Artisan::call('orders:allow-legacy-retry', [
            'orderId' => $orderId,
        ]);
        $this->assertSame(1, $refused);
        $this->assertSame(
            'RECONCILIATION_REQUIRED',
            DB::table('orders')->where('id', $orderId)->value('processing_recovery_state')
        );
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());

        $approved = Artisan::call('orders:allow-legacy-retry', [
            'orderId' => $orderId,
            '--confirmed-no-payment' => true,
        ]);
        $this->assertSame(0, $approved);
        $this->assertSame(
            'SAFE_TO_RECOVER',
            DB::table('orders')->where('id', $orderId)->value('processing_recovery_state')
        );

        Artisan::call('orders:recover-expired');
        $this->assertSame(1, (int) Redis::llen('queues:default'));
        $worker = $this->runner->run([[
            'action' => 'queue-worker',
            'payload' => ['queue' => 'default'],
        ]]);

        $this->assertSame(0, $worker[0]['exit_code']);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PAID',
            'processing_attempt' => 1,
            'payment_idempotency_key' => "order-payment:v1:{$orderId}",
            'processing_recovery_state' => null,
        ]);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'idempotency_key' => "order-payment:v1:{$orderId}",
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);
    }

    private function insertOrder(
        string $status,
        string $idempotencyKey,
        array $overrides = []
    ): int {
        $now = now();
        $orderId = DB::table('orders')->insertGetId(array_merge([
            'user_id' => 1,
            'status' => $status,
            'total_amount' => '999.99',
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => null,
            'processing_token' => null,
            'processing_claimed_at' => null,
            'processing_lease_until' => null,
            'processing_attempt' => 0,
            'payment_idempotency_key' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => 1,
            'quantity' => 1,
            'unit_price' => '999.99',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $orderId;
    }
}
