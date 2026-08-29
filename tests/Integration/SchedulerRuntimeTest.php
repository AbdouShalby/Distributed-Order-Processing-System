<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Support\ConcurrentProcessRunner;

#[Group('scheduler-runtime')]
final class SchedulerRuntimeTest extends IntegrationTestCase
{
    #[Test]
    public function docker_scheduler_requeues_expired_work_before_a_real_worker_processes_it(): void
    {
        $now = now();
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => 1,
            'status' => 'PROCESSING',
            'total_amount' => '999.99',
            'idempotency_key' => 'scheduler-runtime-expired',
            'request_fingerprint' => null,
            'processing_token' => Str::uuid()->toString(),
            'processing_claimed_at' => $now->copy()->subMinutes(2),
            'processing_lease_until' => $now->copy()->subMinute(),
            'processing_attempt' => 2,
            'payment_idempotency_key' => null,
            'processing_recovery_state' => 'LEASED',
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $paymentKey = "provider-operation:v0:{$orderId}";
        DB::table('orders')->where('id', $orderId)->update([
            'payment_idempotency_key' => $paymentKey,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => 1,
            'quantity' => 1,
            'unit_price' => '999.99',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->waitForSchedulerToEnqueueRecovery();

        $this->assertSame(1, (int) Redis::llen('queues:default'));
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PROCESSING',
            'processing_attempt' => 2,
            'payment_idempotency_key' => $paymentKey,
        ]);
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());

        $worker = (new ConcurrentProcessRunner)->run([[
            'action' => 'queue-worker',
            'payload' => ['queue' => 'default'],
        ]]);

        $this->assertSame(0, $worker[0]['exit_code']);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PAID',
            'processing_attempt' => 3,
            'payment_idempotency_key' => $paymentKey,
        ]);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'idempotency_key' => $paymentKey,
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);
    }

    private function waitForSchedulerToEnqueueRecovery(): void
    {
        $deadline = microtime(true) + 75;

        while ((int) Redis::llen('queues:default') === 0) {
            if (microtime(true) >= $deadline) {
                $this->fail(
                    'The Docker scheduler did not enqueue recovery within 75 seconds.'
                );
            }

            usleep(250_000);
        }
    }
}
