<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\UseCases\CreateOrder\CreateOrderUseCase;
use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LockAcquisitionException;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private CreateOrderUseCase $useCase;
    private OrderRepositoryInterface $orderRepo;
    private ProductRepositoryInterface $productRepo;
    private DistributedLockInterface $lock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->productRepo = $this->mock(ProductRepositoryInterface::class);
        $this->lock = $this->mock(DistributedLockInterface::class);

        $this->useCase = new CreateOrderUseCase(
            $this->orderRepo,
            $this->productRepo,
            $this->lock,
        );
    }

    private function makeDTO(array $overrides = []): CreateOrderDTO
    {
        return new CreateOrderDTO(
            userId: $overrides['user_id'] ?? 1,
            items: $overrides['items'] ?? [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 3, 'quantity' => 1],
            ],
            idempotencyKey: $overrides['idempotency_key'] ?? 'test-key-123',
        );
    }

    // ─── Success Path ─────────────────────────────────

    #[Test]
    public function it_creates_order_successfully(): void
    {
        Queue::fake();

        $dto = $this->makeDTO();

        // No existing order (not idempotent duplicate)
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->with('test-key-123')
            ->once()
            ->andReturnNull();

        // Lock succeeds
        $this->lock
            ->shouldReceive('acquireForProducts')
            ->with([1, 3])
            ->once()
            ->andReturn(true);

        // Product lookups with FOR UPDATE
        $this->productRepo
            ->shouldReceive('findByIdForUpdate')
            ->with(1)
            ->once()
            ->andReturn(['id' => 1, 'price' => '29.99', 'stock' => 100]);

        $this->productRepo
            ->shouldReceive('findByIdForUpdate')
            ->with(3)
            ->once()
            ->andReturn(['id' => 3, 'price' => '149.99', 'stock' => 50]);

        // Stock decrements
        $this->productRepo
            ->shouldReceive('decrementStock')
            ->with(1, 2)
            ->once();

        $this->productRepo
            ->shouldReceive('decrementStock')
            ->with(3, 1)
            ->once();

        // Order save returns entity with ID
        $this->orderRepo
            ->shouldReceive('save')
            ->once()
            ->andReturnUsing(function (Order $order) {
                $order->setId(42);

                return $order;
            });

        // Always release locks
        $this->lock
            ->shouldReceive('releaseAll')
            ->once();

        $response = $this->useCase->execute($dto);

        $this->assertSame(42, $response->id);
        $this->assertSame(1, $response->userId);
        $this->assertSame('PENDING', $response->status);
        // 2 × 29.99 + 1 × 149.99 = 209.97
        $this->assertSame('209.97', $response->totalAmount);
        $this->assertSame('test-key-123', $response->idempotencyKey);
        $this->assertCount(2, $response->items);

        Queue::assertPushed(ProcessOrderJob::class, function ($job) {
            return $job->orderId === 42;
        });
    }

    // ─── Idempotency ──────────────────────────────────

    #[Test]
    public function it_returns_existing_order_for_duplicate_idempotency_key(): void
    {
        $existingOrder = new Order(
            id: 99,
            userId: 1,
            status: OrderStatus::PENDING,
            totalAmount: '50.00',
            idempotencyKey: 'test-key-123',
        );
        $existingOrder->setItems([
            new OrderItem(productId: 1, quantity: 1, unitPrice: '50.00'),
        ]);

        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->with('test-key-123')
            ->once()
            ->andReturn($existingOrder);

        // Should NOT acquire locks or create anything
        $this->lock->shouldNotReceive('acquireForProducts');
        $this->productRepo->shouldNotReceive('findByIdForUpdate');
        $this->orderRepo->shouldNotReceive('save');

        $response = $this->useCase->execute($this->makeDTO());

        $this->assertSame(99, $response->id);
        $this->assertSame('50.00', $response->totalAmount);
    }

    // ─── Lock Failure ─────────────────────────────────

    #[Test]
    public function it_throws_lock_exception_when_products_are_locked(): void
    {
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->once()
            ->andReturnNull();

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(false);

        $this->lock
            ->shouldReceive('releaseAll')
            ->never();

        $this->expectException(LockAcquisitionException::class);

        $this->useCase->execute($this->makeDTO());
    }

    // ─── Insufficient Stock ───────────────────────────

    #[Test]
    public function it_throws_when_stock_is_insufficient(): void
    {
        Queue::fake();

        $dto = $this->makeDTO([
            'items' => [['product_id' => 1, 'quantity' => 10]],
        ]);

        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->once()
            ->andReturnNull();

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->with([1])
            ->once()
            ->andReturn(true);

        $this->productRepo
            ->shouldReceive('findByIdForUpdate')
            ->with(1)
            ->once()
            ->andReturn(['id' => 1, 'price' => '29.99', 'stock' => 5]);

        // Locks must be released on failure
        $this->lock
            ->shouldReceive('releaseAll')
            ->once();

        $this->expectException(InsufficientStockException::class);

        $this->useCase->execute($dto);

        Queue::assertNothingPushed();
    }

    // ─── Server-Side Total Calculation ────────────────

    #[Test]
    public function it_calculates_total_server_side_with_decimal_precision(): void
    {
        Queue::fake();

        $dto = $this->makeDTO([
            'items' => [
                ['product_id' => 1, 'quantity' => 3],
            ],
        ]);

        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->once()
            ->andReturnNull();

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(true);

        $this->productRepo
            ->shouldReceive('findByIdForUpdate')
            ->with(1)
            ->once()
            ->andReturn(['id' => 1, 'price' => '9.99', 'stock' => 100]);

        $this->productRepo
            ->shouldReceive('decrementStock')
            ->once();

        $savedOrder = null;
        $this->orderRepo
            ->shouldReceive('save')
            ->once()
            ->andReturnUsing(function (Order $order) use (&$savedOrder) {
                $order->setId(1);
                $savedOrder = $order;

                return $order;
            });

        $this->lock->shouldReceive('releaseAll')->once();

        $response = $this->useCase->execute($dto);

        // 3 × 9.99 = 29.97 (bcmath precision, NOT 29.970000000000002)
        $this->assertSame('29.97', $response->totalAmount);
    }

    // ─── Lock Release on Exception ────────────────────

    #[Test]
    public function it_always_releases_locks_even_on_exception(): void
    {
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->once()
            ->andReturnNull();

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(true);

        $this->productRepo
            ->shouldReceive('findByIdForUpdate')
            ->once()
            ->andReturnNull(); // Product not found → DomainException

        // MUST release locks even though exception is thrown
        $this->lock
            ->shouldReceive('releaseAll')
            ->once();

        $this->expectException(\DomainException::class);

        $this->useCase->execute($this->makeDTO([
            'items' => [['product_id' => 999, 'quantity' => 1]],
        ]));
    }
}
