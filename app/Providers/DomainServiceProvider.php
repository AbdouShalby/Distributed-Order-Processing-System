<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Domain\Order\Contracts\IdempotencyRepositoryInterface;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Infrastructure\Locking\InMemoryDistributedLock;
use App\Infrastructure\Locking\RedisDistributedLock;
use App\Infrastructure\PaymentGateway\SimulatedPaymentGateway;
use App\Infrastructure\Persistence\Repositories\EloquentIdempotencyRepository;
use App\Infrastructure\Persistence\Repositories\EloquentOrderRepository;
use App\Infrastructure\Persistence\Repositories\EloquentProductRepository;
use App\Infrastructure\Persistence\Repositories\EloquentRefundRepository;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    /**
     * All interface → implementation bindings.
     * Swap any implementation here without touching business logic.
     */
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(IdempotencyRepositoryInterface::class, EloquentIdempotencyRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(PaymentGatewayInterface::class, SimulatedPaymentGateway::class);
        $this->app->bind(RefundRepositoryInterface::class, EloquentRefundRepository::class);

        // Use in-memory lock for testing (no Redis), real Redis lock otherwise
        $this->app->bind(
            DistributedLockInterface::class,
            $this->app->environment('testing')
                ? InMemoryDistributedLock::class
                : RedisDistributedLock::class
        );
    }
}
