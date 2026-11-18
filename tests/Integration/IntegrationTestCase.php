<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Infrastructure\Locking\RedisDistributedLock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertRealInfrastructure();
        $this->resetCommittedState();
    }

    private function assertRealInfrastructure(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->fail('Integration suite requires MySQL; refusing a weaker fallback.');
        }
        if (config('queue.default') !== 'redis') {
            $this->fail('Integration suite requires the Redis queue connection.');
        }
        if (config('cache.default') !== 'redis') {
            $this->fail('Integration suite requires the Redis cache store.');
        }
        if (! app(DistributedLockInterface::class) instanceof RedisDistributedLock) {
            $this->fail('Integration suite requires RedisDistributedLock.');
        }
        $redisPing = Redis::ping();

        if (! in_array($redisPing, [true, 1, '1', 'PONG', '+PONG'], true)) {
            $this->fail('Integration suite could not ping real Redis.');
        }
    }

    private function resetCommittedState(): void
    {
        Redis::flushdb();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'simulated_payment_operations',
            'idempotency_requests',
            'order_items',
            'orders',
            'personal_access_tokens',
            'users',
            'products',
        ] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed(DatabaseSeeder::class);
    }
}
