<?php

declare(strict_types=1);

use App\Application\UseCases\CancelOrder\CancelOrderUseCase;
use App\Application\UseCases\ProcessOrder\ProcessOrderUseCase;
use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Integration\Support\IntegrationHttpClient;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $barrier, $actorId, $encodedPayload] = $argv;
$payload = json_decode(base64_decode($encodedPayload, true), true, flags: JSON_THROW_ON_ERROR);
$redis = Redis::connection();
$redis->incr("{$barrier}:ready");

$deadline = microtime(true) + 20;
while ($redis->get("{$barrier}:start") !== 'go') {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Actor {$actorId} timed out at the start barrier.\n");
        exit(2);
    }
    usleep(5_000);
}

try {
    $result = match ($action) {
        'refund-create' => IntegrationHttpClient::request(
            'POST',
            '/api/agent/orders/'.$payload['order_id'].'/refunds',
            $payload['body'],
            $payload['headers']
        ),
        'refund-approve' => IntegrationHttpClient::request(
            'POST',
            '/api/agent/refunds/'.$payload['refund_id'].'/approve',
            $payload['body'],
            $payload['headers']
        ),
        'http' => IntegrationHttpClient::request(
            $payload['method'],
            $payload['path'],
            $payload['body'] ?? null
        ),
        'http-after-legacy-ready' => requestAfterLegacyWriterIsReady(
            $redis,
            $barrier,
            $payload
        ),
        'legacy-order-writer' => createLegacyOrderDuringHttpRequest(
            $redis,
            $barrier,
            $payload
        ),
        'process' => [
            'outcome' => $app->make(ProcessOrderUseCase::class)
                ->execute((int) $payload['order_id'], "integration-actor-{$actorId}")
                ->value,
        ],
        'cancel' => [
            'status' => $app->make(CancelOrderUseCase::class)
                ->execute((int) $payload['order_id'])
                ->status,
        ],
        'claim' => claimOrder($app, $payload),
        'complete' => completeOrder($app, $payload),
        'queue-worker' => workOneQueuedJob($payload),
        'queue-worker-hard-crash-target' => workOneQueuedJobAsCrashTarget(
            $redis,
            $barrier,
            $payload
        ),
        'hard-crash-controller' => terminateAfterProviderPersistence($redis, $barrier),
        'recover-expired' => recoverExpiredProcessingOrders($payload),
        'lock-holder' => holdLock($app, $redis, $barrier, $payload),
        'lock-contender' => contendForLock($app, $redis, $barrier, $payload),
        default => throw new RuntimeException("Unknown integration actor action '{$action}'."),
    };
} catch (Throwable $exception) {
    $result = [
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ];
}

echo json_encode($result, JSON_THROW_ON_ERROR);

function claimOrder($app, array $payload): array
{
    $claim = $app->make(OrderRepositoryInterface::class)->claimForProcessing(
        (int) $payload['order_id'],
        new DateTimeImmutable($payload['claimed_at']),
        new DateTimeImmutable($payload['lease_until']),
        $payload['token']
    );

    return $claim === null
        ? ['claimed' => false]
        : [
            'claimed' => true,
            'attempt' => $claim->attempt,
            'payment_idempotency_key' => $claim->paymentIdempotencyKey,
        ];
}

function completeOrder($app, array $payload): array
{
    return [
        'completed' => $app->make(OrderRepositoryInterface::class)->completeProcessing(
            (int) $payload['order_id'],
            $payload['token'],
            OrderStatus::from($payload['status'])
        ),
    ];
}

function requestAfterLegacyWriterIsReady($redis, string $barrier, array $payload): array
{
    $deadline = microtime(true) + 10;
    while ($redis->get("{$barrier}:legacy-ready") !== 'yes') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('HTTP actor never observed the legacy writer latch.');
        }

        usleep(5_000);
    }

    return IntegrationHttpClient::request(
        $payload['method'],
        $payload['path'],
        $payload['body'] ?? null
    );
}

function createLegacyOrderDuringHttpRequest($redis, string $barrier, array $payload): array
{
    $resumeLock = $payload['resume_lock'];
    $reachedLock = $payload['reached_lock'];
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
    $acquired = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$resumeLock]);
    if ((int) $acquired->acquired !== 1) {
        throw new RuntimeException('Legacy writer could not acquire the HTTP resume latch.');
    }

    $resumeOwner = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [$resumeLock]);
    if ((int) $resumeOwner->owner !== $connectionId) {
        throw new RuntimeException('Legacy writer does not own the HTTP resume latch.');
    }

    $redis->set("{$barrier}:legacy-ready", 'yes', 'EX', 20);

    try {
        $deadline = microtime(true) + 10;
        do {
            $owner = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', [$reachedLock]);
            if ($owner->owner !== null) {
                if ((int) $owner->owner === $connectionId) {
                    throw new RuntimeException('Legacy writer unexpectedly owns the HTTP reached latch.');
                }

                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('HTTP request never reached the MySQL reservation latch.');
            }

            usleep(5_000);
        } while (true);

        $orderId = DB::transaction(function () use ($payload) {
            $quantity = (int) $payload['quantity'];
            $productId = (int) $payload['product_id'];
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->firstOrFail();

            if ((int) $product->stock < $quantity) {
                throw new RuntimeException('Legacy writer did not receive the expected last inventory unit.');
            }

            DB::table('products')->where('id', $productId)->decrement('stock', $quantity);
            $now = now();
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => (int) $payload['user_id'],
                'status' => 'PENDING',
                'total_amount' => bcmul((string) $product->price, (string) $quantity, 2),
                'idempotency_key' => $payload['idempotency_key'],
                'request_fingerprint' => $payload['request_fingerprint'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('order_items')->insert([
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $orderId;
        });

        return ['order_id' => $orderId];
    } finally {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$resumeLock]);
    }
}

function workOneQueuedJob(array $payload): array
{
    $exitCode = Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => $payload['queue'] ?? 'default',
        '--once' => true,
        '--tries' => 3,
        '--timeout' => 15,
        '--sleep' => 0,
        '--no-interaction' => true,
    ]);

    return ['exit_code' => $exitCode];
}

function workOneQueuedJobAsCrashTarget($redis, string $barrier, array $payload): array
{
    $redis->set("{$barrier}:crash-target-pid", (string) getmypid(), 'EX', 30);
    config(['services.simulated_payment.after_persist_barrier' => $barrier]);

    return workOneQueuedJob($payload);
}

function terminateAfterProviderPersistence($redis, string $barrier): array
{
    $deadline = microtime(true) + 20;
    $targetPid = null;
    $providerEvent = null;

    while ($targetPid === null || $providerEvent === null) {
        $targetPid = $redis->get("{$barrier}:crash-target-pid") ?: null;
        $providerEvent = $redis->get("{$barrier}:provider-persisted") ?: null;

        if (microtime(true) >= $deadline) {
            throw new RuntimeException(
                'Crash controller did not observe both the worker PID and provider-persisted latch.'
            );
        }

        usleep(5_000);
    }

    $targetPid = (int) $targetPid;
    if ($targetPid <= 1 || $targetPid === getmypid()) {
        throw new RuntimeException('Crash controller received an unsafe worker PID.');
    }

    $pipes = [];
    $kill = proc_open(
        ['/bin/kill', '-9', (string) $targetPid],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (! is_resource($kill)) {
        throw new RuntimeException('Crash controller could not start /bin/kill.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($kill);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "SIGKILL failed with exit code {$exitCode}: {$stderr}{$stdout}"
        );
    }

    return [
        'target_pid' => $targetPid,
        'signal' => 9,
        'provider_event' => json_decode(
            $providerEvent,
            true,
            flags: JSON_THROW_ON_ERROR
        ),
    ];
}

function recoverExpiredProcessingOrders(array $payload): array
{
    $exitCode = Artisan::call('orders:recover-expired', [
        '--limit' => (int) ($payload['limit'] ?? 100),
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => trim(Artisan::output()),
    ];
}

function holdLock($app, $redis, string $barrier, array $payload): array
{
    $lock = $app->make(DistributedLockInterface::class);
    $acquired = $lock->acquireForProducts(
        $payload['product_ids'],
        (int) ($payload['ttl_seconds'] ?? 10)
    );

    if ($acquired) {
        $redis->set("{$barrier}:lock-held", 'yes', 'EX', 20);
        $deadline = microtime(true) + 15;
        while ($redis->get("{$barrier}:lock-release") !== 'yes') {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Lock holder timed out waiting for the contender.');
            }
            usleep(5_000);
        }
        $lock->releaseAll();
    }

    return ['acquired' => $acquired];
}

function contendForLock($app, $redis, string $barrier, array $payload): array
{
    $deadline = microtime(true) + 10;
    while ($redis->get("{$barrier}:lock-held") !== 'yes') {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Contender never observed the held lock.');
        }
        usleep(5_000);
    }

    $lock = $app->make(DistributedLockInterface::class);
    $acquired = $lock->acquireForProducts(
        $payload['product_ids'],
        (int) ($payload['ttl_seconds'] ?? 10)
    );
    $redis->set("{$barrier}:lock-release", 'yes', 'EX', 20);
    if ($acquired) {
        $lock->releaseAll();
    }

    return ['acquired' => $acquired];
}
