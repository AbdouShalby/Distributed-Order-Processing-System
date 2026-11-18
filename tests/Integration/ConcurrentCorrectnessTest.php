<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\Idempotency\CreateOrderRequestFingerprint;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Payment\Exceptions\PaymentGatewayUnavailableException;
use App\Domain\Payment\Exceptions\PaymentOutcomeUnknownException;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Fixtures\CrashAfterClaimJob;
use Tests\Integration\Support\ConcurrentProcessRunner;
use Tests\Integration\Support\IntegrationHttpClient;

final class ConcurrentCorrectnessTest extends IntegrationTestCase
{
    private ConcurrentProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ConcurrentProcessRunner;
    }

    #[Test]
    public function identical_http_requests_converge_on_one_created_and_one_replayed_result(): void
    {
        $body = $this->orderBody('http-identical', 1, 1);
        $results = $this->runner->run([
            $this->httpActor($body),
            $this->httpActor($body),
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $orderIds = array_map(
            fn (array $result) => $result['body']['data']['id'],
            $results
        );
        $replayed = array_map(
            fn (array $result) => $result['body']['meta']['idempotency_replayed'],
            $results
        );
        sort($replayed);

        $this->assertSame(
            [200, 201],
            $statuses,
            $this->report(2, 'HTTP start barrier', [200, 201], $results)
        );
        $this->assertSame([false, true], $replayed);
        $this->assertCount(1, array_unique($orderIds));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(49, (int) DB::table('products')->where('id', 1)->value('stock'));
        $this->assertGreaterThanOrEqual(1, Redis::llen('queues:default'));
    }

    #[Test]
    public function concurrent_conflicting_fingerprints_create_one_order_and_one_conflict(): void
    {
        $results = $this->runner->run([
            $this->httpActor($this->orderBody('http-conflict', 1, 1)),
            $this->httpActor($this->orderBody('http-conflict', 1, 2)),
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $quantity = (int) DB::table('order_items')->value('quantity');
        $conflict = array_values(array_filter(
            $results,
            fn (array $result) => $result['status'] === 409
        ))[0];

        $this->assertSame(
            [201, 409],
            $statuses,
            $this->report(2, 'HTTP start barrier', [201, 409], $results)
        );
        $this->assertSame('IDEMPOTENCY_CONFLICT', $conflict['body']['error_code']);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(50 - $quantity, (int) DB::table('products')->where('id', 1)->value('stock'));
    }

    #[Test]
    public function legacy_writer_committing_after_the_early_check_reconciles_the_stock_failure(): void
    {
        $idempotencyKey = 'mysql-legacy-unique-race';
        $productId = 5;
        $body = $this->orderBody($idempotencyKey, $productId, 1);
        $fingerprint = CreateOrderRequestFingerprint::fromDTO(new CreateOrderDTO(
            userId: $body['user_id'],
            items: $body['items'],
            idempotencyKey: $idempotencyKey,
        ));

        DB::table('products')->where('id', $productId)->update(['stock' => 1]);
        $this->clearQueue();
        $lockSuffix = str_replace('-', '', Str::uuid()->toString());
        $reachedLock = "m2a1-reached-{$lockSuffix}";
        $resumeLock = "m2a1-resume-{$lockSuffix}";
        DB::unprepared(<<<SQL
            CREATE TRIGGER integration_pause_idempotency_reservation
            BEFORE INSERT ON idempotency_requests
            FOR EACH ROW
            BEGIN
                IF NEW.idempotency_key = 'mysql-legacy-unique-race' THEN
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
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS integration_pause_idempotency_reservation');
        }

        $this->assertSame(1, (int) DB::selectOne(
            'SELECT IS_FREE_LOCK(?) AS is_free',
            [$reachedLock]
        )->is_free);
        $this->assertSame(1, (int) DB::selectOne(
            'SELECT IS_FREE_LOCK(?) AS is_free',
            [$resumeLock]
        )->is_free);

        $legacyWriter = $results[0];
        $http = $results[1];

        $this->assertSame(
            200,
            $http['status'],
            $this->report(
                2,
                'MySQL trigger + named-lock latch after the early legacy check',
                'legacy writer commits one order, HTTP request returns its 200 replay',
                $results
            )
        );
        $this->assertTrue($http['body']['meta']['idempotency_replayed']);
        $this->assertSame($legacyWriter['order_id'], $http['body']['data']['id']);
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(0, (int) DB::table('products')->where('id', $productId)->value('stock'));
        $this->assertDatabaseHas('idempotency_requests', [
            'idempotency_key' => $idempotencyKey,
            'status' => 'COMPLETED',
            'order_id' => $legacyWriter['order_id'],
        ]);
        $this->assertSame(0, (int) Redis::llen('queues:default'));
    }

    #[Test]
    public function concurrent_inventory_reservations_never_over_allocate_stock(): void
    {
        DB::table('products')->where('id', 5)->update(['stock' => 1]);
        $actors = [];
        for ($actor = 0; $actor < 5; $actor++) {
            $actors[] = $this->httpActor($this->orderBody("inventory-{$actor}", 5, 1));
        }

        $results = $this->runner->run($actors);
        $statuses = array_column($results, 'status');

        $this->assertSame(
            1,
            count(array_filter($statuses, fn (int $status) => $status === 201)),
            $this->report(5, 'HTTP start barrier', 'one 201 and four 409 responses', $results)
        );
        $this->assertSame(4, count(array_filter($statuses, fn (int $status) => $status === 409)));
        $this->assertSame(0, (int) DB::table('products')->where('id', 5)->value('stock'));
        $this->assertSame(1, (int) DB::table('order_items')->where('product_id', 5)->sum('quantity'));
    }

    #[Test]
    public function simultaneous_workers_produce_one_claim_and_one_payment_side_effect(): void
    {
        $orderId = $this->createPendingOrder('duplicate-workers');
        $results = $this->runner->run([
            $this->processActor($orderId),
            $this->processActor($orderId),
        ]);

        $outcomes = array_column($results, 'outcome');
        sort($outcomes);

        $this->assertSame(
            ['paid', 'skipped'],
            $outcomes,
            $this->report(2, 'worker start barrier', ['paid', 'skipped'], $results)
        );
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertPaymentCounts($orderId, 1, 1);

        $duplicates = $this->runner->run([
            $this->processActor($orderId),
            $this->processActor($orderId),
        ]);
        $this->assertSame(['skipped', 'skipped'], array_column($duplicates, 'outcome'));
        $this->assertPaymentCounts($orderId, 1, 1);
    }

    #[Test]
    public function real_redis_queue_duplicate_delivery_is_safely_suppressed(): void
    {
        $orderId = $this->createPendingOrder('redis-queue-duplicates');
        $this->clearQueue();
        ProcessOrderJob::dispatch($orderId, 'queue-duplicate-1')->onQueue('duplicate-a');
        ProcessOrderJob::dispatch($orderId, 'queue-duplicate-2')->onQueue('duplicate-b');
        $this->assertSame(1, (int) Redis::llen('queues:duplicate-a'));
        $this->assertSame(1, (int) Redis::llen('queues:duplicate-b'));

        $results = $this->runner->run([
            $this->queueWorkerActor([], 'duplicate-a'),
            $this->queueWorkerActor([], 'duplicate-b'),
        ]);

        $this->assertSame(
            [0, 0],
            array_column($results, 'exit_code'),
            $this->report(2, 'Redis queue pop after process barrier', [0, 0], $results)
        );
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertPaymentCounts($orderId, 1, 1);
        $this->assertSame(0, (int) Redis::llen('queues:duplicate-a'));
        $this->assertSame(0, (int) Redis::llen('queues:duplicate-b'));
    }

    #[Test]
    public function redis_lock_contention_fails_the_contender_without_releasing_the_holder_lock(): void
    {
        $payload = ['product_ids' => [1], 'ttl_seconds' => 10];
        $results = $this->runner->run([
            ['action' => 'lock-holder', 'payload' => $payload],
            ['action' => 'lock-contender', 'payload' => $payload],
        ]);

        $report = $this->report(
            2,
            'holder-acquired/contender-observed Redis latch',
            [['acquired' => true], ['acquired' => false]],
            $results
        );
        $this->assertTrue($results[0]['acquired'], $report);
        $this->assertFalse($results[1]['acquired'], $report);
    }

    #[Test]
    public function simultaneous_cancellations_restore_inventory_once(): void
    {
        $orderId = $this->createPendingOrder('cancel-contention', 1, 2);
        $results = $this->runner->run([
            $this->cancelActor($orderId),
            $this->cancelActor($orderId),
        ]);

        $this->assertSame(
            ['CANCELLED', 'CANCELLED'],
            array_column($results, 'status'),
            $this->report(
                2,
                'cancellation start barrier',
                ['CANCELLED', 'CANCELLED'],
                $results
            )
        );
        $this->assertSame('CANCELLED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(50, (int) DB::table('products')->where('id', 1)->value('stock'));
    }

    #[Test]
    public function processing_and_cancellation_race_has_one_valid_state_winner(): void
    {
        $orderId = $this->createPendingOrder('cancel-process-race');
        $results = $this->runner->run([
            $this->processActor($orderId),
            $this->cancelActor($orderId),
        ]);
        $status = DB::table('orders')->where('id', $orderId)->value('status');

        $this->assertContains(
            $status,
            ['PAID', 'CANCELLED'],
            $this->report(2, 'state transition barrier', ['PAID', 'CANCELLED'], $results)
        );
        if ($status === 'PAID') {
            $this->assertSame('paid', $results[0]['outcome']);
            $this->assertSame(OrderNotCancellableException::class, $results[1]['exception']);
            $this->assertSame(49, (int) DB::table('products')->where('id', 1)->value('stock'));
            $this->assertPaymentCounts($orderId, 1, 1);
        } else {
            $this->assertSame('skipped', $results[0]['outcome']);
            $this->assertSame('CANCELLED', $results[1]['status']);
            $this->assertSame(50, (int) DB::table('products')->where('id', 1)->value('stock'));
            $this->assertSame(0, DB::table('simulated_payment_operations')->count());
        }
    }

    #[Test]
    public function crashed_claim_is_protected_until_expiry_and_then_recovered(): void
    {
        $orderId = $this->createPendingOrder('crash-recovery');
        $now = now()->toImmutable();
        $first = $this->runner->run([
            $this->claimActor(
                $orderId,
                $now->toIso8601String(),
                $now->addSeconds(30)->toIso8601String(),
                'crashed-worker'
            ),
        ]);
        $blocked = $this->runner->run([
            $this->claimActor(
                $orderId,
                $now->addSecond()->toIso8601String(),
                $now->addSeconds(31)->toIso8601String(),
                'early-retry'
            ),
        ]);

        $this->assertTrue($first[0]['claimed']);
        $this->assertFalse($blocked[0]['claimed']);

        DB::table('orders')->where('id', $orderId)->update([
            'processing_lease_until' => now()->subMinute(),
        ]);
        $recovered = $this->runner->run([$this->processActor($orderId)]);

        $this->assertSame('paid', $recovered[0]['outcome']);
        $this->assertSame(2, (int) DB::table('orders')->where('id', $orderId)->value('processing_attempt'));
        $this->assertPaymentCounts($orderId, 1, 1);
    }

    #[Test]
    public function stale_claim_token_cannot_finalize_after_reclaim(): void
    {
        $orderId = $this->createPendingOrder('stale-token');
        $now = now()->toImmutable();
        $first = $this->runner->run([
            $this->claimActor(
                $orderId,
                $now->toIso8601String(),
                $now->addSecond()->toIso8601String(),
                'superseded-token'
            ),
        ]);
        $this->assertTrue($first[0]['claimed']);

        DB::table('orders')->where('id', $orderId)->update([
            'processing_lease_until' => now()->subSecond(),
        ]);
        $reclaimedAt = now()->toImmutable();
        $second = $this->runner->run([
            $this->claimActor(
                $orderId,
                $reclaimedAt->toIso8601String(),
                $reclaimedAt->addSeconds(30)->toIso8601String(),
                'current-token'
            ),
        ]);
        $this->assertTrue($second[0]['claimed']);

        $stale = $this->runner->run([
            $this->completeActor($orderId, 'superseded-token', 'PAID'),
        ]);
        $winner = $this->runner->run([
            $this->completeActor($orderId, 'current-token', 'PAID'),
        ]);

        $this->assertFalse($stale[0]['completed']);
        $this->assertTrue($winner[0]['completed']);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(0, DB::table('simulated_payment_operations')->count());
    }

    #[Test]
    public function crashed_redis_queue_worker_is_redelivered_and_recovers_after_lease_expiry(): void
    {
        $orderId = $this->createPendingOrder('queue-crash-recovery');
        $this->clearQueue();
        CrashAfterClaimJob::dispatch($orderId, 'queue-crash-recovery');

        $crashed = $this->runner->run([
            $this->queueWorkerActor([86]),
        ]);
        $this->assertSame(86, $crashed[0]['exit_code']);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, (int) Redis::zcard('queues:default:reserved'));

        $protected = $this->runner->run([$this->processActor($orderId)]);
        $this->assertSame('skipped', $protected[0]['outcome']);

        $this->waitForReservedJobAndLeaseToExpire($orderId);
        $redelivered = $this->runner->run([$this->queueWorkerActor()]);
        $this->assertSame(0, $redelivered[0]['exit_code']);
        $this->assertSame(1, (int) Redis::llen('queues:default'));

        $recovered = $this->runner->run([$this->queueWorkerActor()]);
        $this->assertSame(0, $recovered[0]['exit_code']);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(2, (int) DB::table('orders')->where('id', $orderId)->value('processing_attempt'));
        $this->assertPaymentCounts($orderId, 1, 1);
    }

    #[Test]
    public function ambiguous_timeout_and_pre_acceptance_failure_retry_safely(): void
    {
        $timeoutOrder = $this->createPendingOrder('ambiguous-timeout');
        $timedOut = $this->runner->run([
            $this->processActor($timeoutOrder, [
                'SIMULATED_PAYMENT_TIMEOUT_AFTER_PERSIST' => 'true',
            ]),
        ]);

        $this->assertSame(PaymentOutcomeUnknownException::class, $timedOut[0]['exception']);
        $this->assertPaymentCounts($timeoutOrder, 1, 1);

        $reconciled = $this->runner->run([
            $this->processActor($timeoutOrder, [
                'SIMULATED_PAYMENT_TIMEOUT_AFTER_PERSIST' => 'false',
            ]),
        ]);
        $this->assertSame('paid', $reconciled[0]['outcome']);
        $this->assertPaymentCounts($timeoutOrder, 2, 1);

        $unavailableOrder = $this->createPendingOrder('pre-provider-failure', 2);
        $failed = $this->runner->run([
            $this->processActor($unavailableOrder, [
                'SIMULATED_PAYMENT_FAIL_BEFORE_PERSIST' => 'true',
            ]),
        ]);
        $this->assertSame(PaymentGatewayUnavailableException::class, $failed[0]['exception']);
        $this->assertSame(
            0,
            DB::table('simulated_payment_operations')->where('order_id', $unavailableOrder)->count()
        );

        $retried = $this->runner->run([
            $this->processActor($unavailableOrder, [
                'SIMULATED_PAYMENT_FAIL_BEFORE_PERSIST' => 'false',
            ]),
        ]);
        $this->assertSame('paid', $retried[0]['outcome']);
        $this->assertPaymentCounts($unavailableOrder, 1, 1);
    }

    #[Test]
    public function concurrent_cancellation_failures_roll_back_state_and_inventory(): void
    {
        $orderId = $this->createPendingOrder('cancel-rollback', 1, 2);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER integration_fail_stock_restore
            BEFORE UPDATE ON products
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Injected inventory restoration failure'
        SQL);

        try {
            $results = $this->runner->run([
                $this->cancelActor($orderId),
                $this->cancelActor($orderId),
            ]);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS integration_fail_stock_restore');
        }

        $exceptions = array_column($results, 'exception');
        $this->assertSame(
            [\Illuminate\Database\QueryException::class, \Illuminate\Database\QueryException::class],
            $exceptions,
            $this->report(
                2,
                'cancellation start barrier plus MySQL failure trigger',
                'both transactions fail and roll back',
                $results
            )
        );
        $this->assertSame('PENDING', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(48, (int) DB::table('products')->where('id', 1)->value('stock'));
    }

    private function createPendingOrder(
        string $idempotencyKey,
        int $productId = 1,
        int $quantity = 1
    ): int {
        $response = IntegrationHttpClient::request(
            'POST',
            '/api/orders',
            $this->orderBody($idempotencyKey, $productId, $quantity)
        );
        $this->assertSame(201, $response['status']);

        return (int) $response['body']['data']['id'];
    }

    private function orderBody(
        string $idempotencyKey,
        int $productId,
        int $quantity
    ): array {
        return [
            'user_id' => 1,
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['product_id' => $productId, 'quantity' => $quantity],
            ],
        ];
    }

    private function httpActor(array $body): array
    {
        return [
            'action' => 'http',
            'payload' => [
                'method' => 'POST',
                'path' => '/api/orders',
                'body' => $body,
            ],
        ];
    }

    private function processActor(int $orderId, array $env = []): array
    {
        return [
            'action' => 'process',
            'payload' => ['order_id' => $orderId],
            'env' => $env,
        ];
    }

    private function cancelActor(int $orderId): array
    {
        return [
            'action' => 'cancel',
            'payload' => ['order_id' => $orderId],
        ];
    }

    private function claimActor(
        int $orderId,
        string $claimedAt,
        string $leaseUntil,
        string $token
    ): array {
        return [
            'action' => 'claim',
            'payload' => [
                'order_id' => $orderId,
                'claimed_at' => $claimedAt,
                'lease_until' => $leaseUntil,
                'token' => $token,
            ],
        ];
    }

    private function completeActor(int $orderId, string $token, string $status): array
    {
        return [
            'action' => 'complete',
            'payload' => [
                'order_id' => $orderId,
                'token' => $token,
                'status' => $status,
            ],
        ];
    }

    private function queueWorkerActor(array $allowedExitCodes = [], string $queue = 'default'): array
    {
        return [
            'action' => 'queue-worker',
            'payload' => ['queue' => $queue],
            'allowed_exit_codes' => $allowedExitCodes,
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
        $deadline = microtime(true) + 10;

        while (time() < $readyAt) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the persisted queue reservation and processing lease.');
            }
            usleep(20_000);
        }
    }

    private function assertPaymentCounts(
        int $orderId,
        int $requestCount,
        int $sideEffectCount
    ): void {
        $operation = DB::table('simulated_payment_operations')
            ->where('order_id', $orderId)
            ->first();
        $this->assertNotNull($operation);
        $this->assertSame($requestCount, (int) $operation->request_count);
        $this->assertSame($sideEffectCount, (int) $operation->side_effect_count);
        $this->assertSame("order-payment:v1:{$orderId}", $operation->idempotency_key);
    }

    private function report(
        int $actors,
        string $synchronizationPoint,
        mixed $expected,
        array $observed
    ): string {
        return json_encode([
            'concurrent_actors' => $actors,
            'synchronization_point' => $synchronizationPoint,
            'expected' => $expected,
            'observed' => $observed,
            'observed_side_effect_count' => (int) DB::table('simulated_payment_operations')
                ->sum('side_effect_count'),
        ], JSON_THROW_ON_ERROR);
    }
}
