<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\Idempotency\CreateOrderRequestFingerprint;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Support\ConcurrentProcessRunner;
use Tests\Integration\Support\IntegrationHttpClient;

final class Milestone2A4FailureWindowTest extends IntegrationTestCase
{
    private ConcurrentProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ConcurrentProcessRunner;
    }

    #[Test]
    public function real_mysql_orders_unique_index_race_converges_to_replay(): void
    {
        $idempotencyKey = 'mysql-orders-unique-race';
        $productId = 5;
        $body = $this->orderBody($idempotencyKey, $productId, 1);
        $fingerprint = CreateOrderRequestFingerprint::fromDTO(new CreateOrderDTO(
            userId: $body['user_id'],
            items: $body['items'],
            idempotencyKey: $idempotencyKey,
        ));

        DB::table('products')->where('id', $productId)->update(['stock' => 2]);
        $this->clearQueue();
        $suffix = str_replace('-', '', Str::uuid()->toString());
        $reachedLock = "m2a4-reached-{$suffix}";
        $resumeLock = "m2a4-resume-{$suffix}";

        // Business rows remain transactional InnoDB data. This test-only
        // non-transactional observer survives the losing transaction rollback
        // so the assertion can prove that two MySQL connections attempted the
        // orders insert before the unique index selected one durable winner.
        DB::unprepared(<<<'SQL'
            CREATE TABLE integration_order_insert_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor_connection BIGINT UNSIGNED NOT NULL,
                request_fingerprint CHAR(64) NOT NULL
            ) ENGINE=MyISAM
        SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER integration_count_order_insert_attempts
            BEFORE INSERT ON orders
            FOR EACH ROW
            BEGIN
                IF NEW.idempotency_key = '{$idempotencyKey}' THEN
                    INSERT INTO integration_order_insert_attempts (
                        actor_connection,
                        request_fingerprint
                    ) VALUES (CONNECTION_ID(), NEW.request_fingerprint);
                END IF;
            END
        SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER integration_pause_idempotency_reservation_m2a4
            BEFORE INSERT ON idempotency_requests
            FOR EACH ROW
            BEGIN
                IF NEW.idempotency_key = '{$idempotencyKey}' THEN
                    SET @integration_reached = GET_LOCK('{$reachedLock}', 0);
                    IF @integration_reached <> 1 THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Could not acquire HTTP reached latch';
                    END IF;

                    SET @integration_resume = GET_LOCK('{$resumeLock}', 15);
                    IF @integration_resume <> 1 THEN
                        SET @integration_release_reached = RELEASE_LOCK('{$reachedLock}');
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Timed out waiting for HTTP resume latch';
                    END IF;

                    SET @integration_release_reached = RELEASE_LOCK('{$reachedLock}');
                    SET @integration_release_resume = RELEASE_LOCK('{$resumeLock}');
                END IF;
            END
        SQL);

        try {
            $results = $this->runner->run([
                [
                    'action' => 'legacy-order-writer',
                    'payload' => [
                        'idempotency_key' => $idempotencyKey,
                        'request_fingerprint' => $fingerprint,
                        'user_id' => 1,
                        'product_id' => $productId,
                        'quantity' => 1,
                        'reached_lock' => $reachedLock,
                        'resume_lock' => $resumeLock,
                    ],
                ],
                [
                    'action' => 'http-after-legacy-ready',
                    'payload' => [
                        'method' => 'POST',
                        'path' => '/api/orders',
                        'body' => $body,
                    ],
                ],
            ]);

            $attempts = DB::table('integration_order_insert_attempts')->get();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS integration_pause_idempotency_reservation_m2a4');
            DB::unprepared('DROP TRIGGER IF EXISTS integration_count_order_insert_attempts');
            DB::unprepared('DROP TABLE IF EXISTS integration_order_insert_attempts');
        }

        $legacyWriter = $results[0];
        $httpReplay = $results[1];
        $artifact = [
            'actor_count' => 2,
            'actor_roles' => [
                'legacy_application_writer' => 'creator',
                'http_request' => 'replay',
            ],
            'synchronization_point' => 'MySQL reservation trigger plus named locks',
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $fingerprint,
            'order_id' => $legacyWriter['order_id'],
            'mysql_order_insert_attempts' => $attempts->count(),
            'actor_connections' => $attempts->pluck('actor_connection')->unique()->count(),
            'http_status' => $httpReplay['status'],
            'replayed' => $httpReplay['body']['meta']['idempotency_replayed'],
            'observed_side_effect_count' => 0,
            'final_order_status' => DB::table('orders')
                ->where('id', $legacyWriter['order_id'])
                ->value('status'),
            'final_stock' => (int) DB::table('products')->where('id', $productId)->value('stock'),
        ];
        $this->emitArtifact('unique-index-race', $artifact);

        $this->assertSame(2, $attempts->count(), json_encode($artifact, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $attempts->pluck('actor_connection')->unique()->count());
        $this->assertSame([$fingerprint], $attempts->pluck('request_fingerprint')->unique()->values()->all());
        $this->assertSame(200, $httpReplay['status']);
        $this->assertTrue($httpReplay['body']['meta']['idempotency_replayed']);
        $this->assertSame($legacyWriter['order_id'], $httpReplay['body']['data']['id']);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame($fingerprint, DB::table('orders')->where('id', $legacyWriter['order_id'])->value('request_fingerprint'));
        $this->assertSame(1, (int) DB::table('products')->where('id', $productId)->value('stock'));
        $this->assertSame(0, (int) Redis::llen('queues:default'));
    }

    #[Test]
    public function sigkill_after_provider_persistence_recovers_with_one_side_effect(): void
    {
        $orderId = $this->createPendingOrder('hard-crash-after-provider-persist');
        $this->clearQueue();
        ProcessOrderJob::dispatch($orderId, 'm2a4-hard-crash');

        $crashed = $this->runner->run([
            [
                'action' => 'queue-worker-hard-crash-target',
                'payload' => ['queue' => 'default'],
                'env' => ['ORDER_PROCESSING_LEASE_SECONDS' => '2'],
                'allowed_exit_codes' => [9, 137],
            ],
            ['action' => 'hard-crash-controller'],
        ]);

        $crashedOrder = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $providerOperation = DB::table('simulated_payment_operations')
            ->where('order_id', $orderId)
            ->firstOrFail();
        $providerIdentity = (string) $crashedOrder->payment_idempotency_key;
        $crashArtifact = [
            'actor_count' => 2,
            'synchronization_point' => 'provider-persisted Redis latch',
            'order_id' => $orderId,
            'idempotency_key' => 'hard-crash-after-provider-persist',
            'provider_identity' => $providerIdentity,
            'worker_exit_code' => $crashed[0]['exit_code'],
            'signal' => $crashed[1]['signal'],
            'provider_event' => $crashed[1]['provider_event'],
            'observed_side_effect_count' => (int) $providerOperation->side_effect_count,
            'final_order_status' => $crashedOrder->status,
        ];
        $this->emitArtifact('hard-crash', $crashArtifact);

        $this->assertContains($crashed[0]['exit_code'], [9, 137], json_encode($crashArtifact, JSON_THROW_ON_ERROR));
        $this->assertSame(9, $crashed[1]['signal']);
        $this->assertSame($orderId, $crashed[1]['provider_event']['order_id']);
        $this->assertSame($providerIdentity, $crashed[1]['provider_event']['provider_identity']);
        $this->assertSame('PROCESSING', $crashedOrder->status);
        $this->assertSame(1, (int) $crashedOrder->processing_attempt);
        $this->assertSame('SUCCEEDED', $providerOperation->status);
        $this->assertSame(1, (int) $providerOperation->request_count);
        $this->assertSame(1, (int) $providerOperation->side_effect_count);
        $this->assertSame(1, (int) Redis::zcard('queues:default:reserved'));

        $protected = $this->runner->run([
            ['action' => 'process', 'payload' => ['order_id' => $orderId]],
        ]);
        $this->assertSame('skipped', $protected[0]['outcome']);

        $this->waitForReservedJobAndLeaseToExpire($orderId);
        $recovery = $this->runner->run([
            ['action' => 'recover-expired', 'payload' => ['limit' => 100]],
        ]);
        $this->assertSame(0, $recovery[0]['exit_code']);
        $this->assertStringContainsString('Requeued 1 recoverable processing order', $recovery[0]['output']);

        $workers = $this->runner->run([
            ['action' => 'queue-worker', 'payload' => ['queue' => 'default']],
            ['action' => 'queue-worker', 'payload' => ['queue' => 'default']],
        ]);
        $backlogDrain = $this->runner->run([
            ['action' => 'queue-worker', 'payload' => ['queue' => 'default']],
        ]);
        $recoveredOrder = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $this->assertSame('PAID', $recoveredOrder->status);
        $this->assertSame(0, (int) Redis::llen('queues:default'));
        $this->assertSame(0, (int) Redis::zcard('queues:default:reserved'));

        ProcessOrderJob::dispatch($orderId, 'm2a4-post-success-redelivery');
        $this->assertSame(1, (int) Redis::llen('queues:default'));
        $postSuccessDelivery = $this->runner->run([
            ['action' => 'queue-worker', 'payload' => ['queue' => 'default']],
        ]);
        $finalOrder = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $finalOperation = DB::table('simulated_payment_operations')
            ->where('order_id', $orderId)
            ->firstOrFail();
        $recoveryArtifact = [
            'actor_count' => 2,
            'synchronization_point' => 'real Redis queue-worker start barrier',
            'order_id' => $orderId,
            'provider_identity_before_recovery' => $providerIdentity,
            'provider_identity_after_recovery' => $finalOrder->payment_idempotency_key,
            'recovery_result' => $recovery[0],
            'worker_results' => $workers,
            'backlog_drain' => $backlogDrain[0],
            'post_success_delivery' => $postSuccessDelivery[0],
            'observed_side_effect_count' => (int) $finalOperation->side_effect_count,
            'final_order_status' => $finalOrder->status,
        ];
        $this->emitArtifact('hard-crash-recovery', $recoveryArtifact);

        $this->assertSame('PAID', $finalOrder->status, json_encode($recoveryArtifact, JSON_THROW_ON_ERROR));
        $this->assertSame(2, (int) $finalOrder->processing_attempt);
        $this->assertSame($providerIdentity, $finalOrder->payment_idempotency_key);
        $this->assertSame(1, DB::table('simulated_payment_operations')->where('order_id', $orderId)->count());
        $this->assertSame(2, (int) $finalOperation->request_count);
        $this->assertSame(1, (int) $finalOperation->side_effect_count);
        $this->assertSame([0, 0], array_column($workers, 'exit_code'));
        $this->assertSame(0, $backlogDrain[0]['exit_code']);
        $this->assertSame(0, $postSuccessDelivery[0]['exit_code']);
        $this->assertSame(0, (int) Redis::llen('queues:default'));
        $this->assertSame(0, (int) Redis::zcard('queues:default:reserved'));
    }

    private function createPendingOrder(string $idempotencyKey): int
    {
        $response = IntegrationHttpClient::request(
            'POST',
            '/api/orders',
            $this->orderBody($idempotencyKey, 1, 1)
        );
        $this->assertSame(201, $response['status']);

        return (int) $response['body']['data']['id'];
    }

    private function orderBody(string $idempotencyKey, int $productId, int $quantity): array
    {
        return [
            'user_id' => 1,
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['product_id' => $productId, 'quantity' => $quantity],
            ],
        ];
    }

    private function clearQueue(): void
    {
        Redis::del(
            'queues:default',
            'queues:default:delayed',
            'queues:default:reserved',
            'queues:default:notify'
        );
    }

    private function waitForReservedJobAndLeaseToExpire(int $orderId): void
    {
        $reserved = Redis::zrange('queues:default:reserved', 0, 0, true);
        $this->assertNotEmpty($reserved, 'The crashed queue job was not reserved in Redis.');
        $retryAt = (int) array_values($reserved)[0];
        $leaseUntil = strtotime((string) DB::table('orders')
            ->where('id', $orderId)
            ->value('processing_lease_until'));
        $readyAt = max($retryAt, $leaseUntil) + 1;
        $deadline = microtime(true) + 12;

        while (time() < $readyAt) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the queue reservation and processing lease.');
            }
            usleep(20_000);
        }
    }

    private function emitArtifact(string $scenario, array $artifact): void
    {
        fwrite(STDOUT, sprintf(
            "\nM2A4_ARTIFACT %s %s\n",
            $scenario,
            json_encode($artifact, JSON_THROW_ON_ERROR)
        ));
    }
}
