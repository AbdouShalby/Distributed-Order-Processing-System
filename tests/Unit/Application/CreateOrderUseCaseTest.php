<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\EventHandlers\OrderCreatedHandler;
use App\Application\Idempotency\CreateOrderRequestFingerprint;
use App\Application\UseCases\CreateOrder\CreateOrderUseCase;
use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LockAcquisitionException;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\InMemoryIdempotencyRepository;
use Tests\TestCase;

class CreateOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private CreateOrderUseCase $useCase;

    private OrderRepositoryInterface $orderRepo;

    private DistributedLockInterface $lock;

    private InventoryService $inventoryService;

    private OrderCreatedHandler $orderCreatedHandler;

    private InMemoryIdempotencyRepository $idempotencyRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->lock = $this->mock(DistributedLockInterface::class);
        $this->inventoryService = $this->mock(InventoryService::class);
        $this->orderCreatedHandler = $this->mock(OrderCreatedHandler::class);
        $this->idempotencyRepository = new InMemoryIdempotencyRepository;
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->byDefault()
            ->andReturn(null);
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKeyForUpdate')
            ->byDefault()
            ->andReturn(null);

        $this->useCase = new CreateOrderUseCase(
            $this->orderRepo,
            $this->idempotencyRepository,
            $this->lock,
            $this->inventoryService,
            $this->orderCreatedHandler,
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

        // Lock succeeds
        $this->lock
            ->shouldReceive('acquireForProducts')
            ->with([1, 3])
            ->once()
            ->andReturn(true);

        // InventoryService validates stock + returns OrderItems
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->with([
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 3, 'quantity' => 1],
            ])
            ->once()
            ->andReturn([
                new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
                new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
            ]);

        // InventoryService decrements stock
        $this->inventoryService
            ->shouldReceive('decrementStock')
            ->once();

        // Order save returns entity with ID
        $this->orderRepo
            ->shouldReceive('save')
            ->once()
            ->andReturnUsing(function (Order $order) {
                $order->setId(42);

                return $order;
            });

        // Event handler fires
        $this->orderCreatedHandler
            ->shouldReceive('handle')
            ->once();

        // Always release locks
        $this->lock
            ->shouldReceive('releaseAll')
            ->once();

        $result = $this->useCase->execute($dto);

        $this->assertTrue($result->isCreated());
        $this->assertSame(42, $result->order->id);
        $this->assertSame(1, $result->order->userId);
        $this->assertSame('PENDING', $result->order->status);
        // 2 × 29.99 + 1 × 149.99 = 209.97
        $this->assertSame('209.97', $result->order->totalAmount);
        $this->assertSame('test-key-123', $result->order->idempotencyKey);
        $this->assertCount(2, $result->order->items);

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
            totalAmount: '209.97',
            idempotencyKey: 'test-key-123',
        );
        $existingOrder->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        $dto = $this->makeDTO();
        $this->idempotencyRepository->seedCompleted(
            'test-key-123',
            CreateOrderRequestFingerprint::fromDTO($dto),
            99
        );
        $this->orderRepo
            ->shouldReceive('findById')
            ->with(99)
            ->once()
            ->andReturn($existingOrder);

        // Should NOT acquire locks or create anything
        $this->lock->shouldNotReceive('acquireForProducts');
        $this->inventoryService->shouldNotReceive('validateAndPrepareItems');
        $this->orderRepo->shouldNotReceive('save');

        $result = $this->useCase->execute($dto);

        $this->assertFalse($result->isCreated());
        $this->assertSame(99, $result->order->id);
        $this->assertSame('209.97', $result->order->totalAmount);
    }

    // ─── Idempotency Race (unique-index backstop) ─────

    #[Test]
    public function it_returns_existing_order_when_concurrent_request_wins_the_unique_index(): void
    {
        Queue::fake();

        $dto = $this->makeDTO();

        $existingOrder = new Order(
            id: 77,
            userId: 1,
            status: OrderStatus::PENDING,
            totalAmount: '209.97',
            idempotencyKey: 'test-key-123',
        );
        $existingOrder->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->with('test-key-123')
            ->twice()
            ->andReturn(null, $existingOrder);

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(true);

        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andReturn([
                new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
                new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
            ]);

        $this->inventoryService
            ->shouldReceive('decrementStock')
            ->once();

        // The concurrent winner already committed — our INSERT trips the
        // idx_orders_idempotency unique index (MySQL error 1062).
        $this->orderRepo
            ->shouldReceive('save')
            ->once()
            ->andThrow($this->duplicateIdempotencyKeyException());

        // Race resolved by returning the existing order — no re-dispatch, no event.
        $this->orderCreatedHandler->shouldNotReceive('handle');

        $this->lock->shouldReceive('releaseAll')->once();

        $result = $this->useCase->execute($dto);

        $this->assertFalse($result->isCreated());
        $this->assertSame(77, $result->order->id);
        $this->assertSame('209.97', $result->order->totalAmount);

        // Winner already dispatched the job; the loser must not dispatch again.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_replays_a_legacy_order_before_inventory_validation(): void
    {
        $dto = $this->makeDTO();
        $existingOrder = new Order(
            id: 88,
            userId: 1,
            status: OrderStatus::PAID,
            totalAmount: '209.97',
            idempotencyKey: 'test-key-123',
        );
        $existingOrder->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        $this->orderRepo
            ->shouldReceive('findByIdempotencyKey')
            ->with('test-key-123')
            ->once()
            ->andReturn($existingOrder);
        $this->lock->shouldNotReceive('acquireForProducts');
        $this->inventoryService->shouldNotReceive('validateAndPrepareItems');
        $this->inventoryService->shouldNotReceive('decrementStock');

        $result = $this->useCase->execute($dto);

        $this->assertFalse($result->isCreated());
        $this->assertSame(88, $result->order->id);
        $this->assertSame('PAID', $result->order->status);
    }

    #[Test]
    public function it_reconciles_a_stock_failure_when_a_legacy_order_wins_after_the_early_check(): void
    {
        Queue::fake();
        $dto = $this->makeDTO();
        $existingOrder = new Order(
            id: 89,
            userId: 1,
            status: OrderStatus::PENDING,
            totalAmount: '209.97',
            idempotencyKey: 'test-key-123',
            requestFingerprint: CreateOrderRequestFingerprint::fromDTO($dto),
        );
        $existingOrder->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        $this->lock->shouldReceive('acquireForProducts')->once()->andReturnTrue();
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andThrow(InsufficientStockException::forProduct(1, 2, 0));
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKeyForUpdate')
            ->with('test-key-123')
            ->once()
            ->andReturn($existingOrder);
        $this->inventoryService->shouldNotReceive('decrementStock');
        $this->orderRepo->shouldNotReceive('save');
        $this->orderCreatedHandler->shouldNotReceive('handle');
        $this->lock->shouldReceive('releaseAll')->once();

        $result = $this->useCase->execute($dto);

        $this->assertFalse($result->isCreated());
        $this->assertSame(89, $result->order->id);
        $record = $this->idempotencyRepository->find('test-key-123');
        $this->assertTrue($record->isCompleted());
        $this->assertSame(89, $record->orderId);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rejects_a_conflicting_legacy_order_found_while_reconciling_stock(): void
    {
        $dto = $this->makeDTO();
        $conflictingDto = $this->makeDTO([
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
        $conflictingOrder = new Order(
            id: 90,
            userId: 1,
            status: OrderStatus::PENDING,
            totalAmount: '29.99',
            idempotencyKey: 'test-key-123',
            requestFingerprint: CreateOrderRequestFingerprint::fromDTO($conflictingDto),
        );
        $conflictingOrder->setItems([
            new OrderItem(productId: 1, quantity: 1, unitPrice: '29.99'),
        ]);

        $this->lock->shouldReceive('acquireForProducts')->once()->andReturnTrue();
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andThrow(InsufficientStockException::forProduct(1, 2, 0));
        $this->orderRepo
            ->shouldReceive('findByIdempotencyKeyForUpdate')
            ->with('test-key-123')
            ->once()
            ->andReturn($conflictingOrder);
        $this->inventoryService->shouldNotReceive('decrementStock');
        $this->orderRepo->shouldNotReceive('save');
        $this->orderCreatedHandler->shouldNotReceive('handle');
        $this->lock->shouldReceive('releaseAll')->once();

        $this->expectException(IdempotencyConflictException::class);

        $this->useCase->execute($dto);
    }

    private function duplicateIdempotencyKeyException(): QueryException
    {
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 1062 '
            ."Duplicate entry 'test-key-123' for key 'idx_orders_idempotency'";

        $pdo = new \PDOException($message);
        $pdo->errorInfo = ['23000', 1062, $message];

        return new QueryException('mysql', 'insert into `orders` (...) values (...)', [], $pdo);
    }

    // ─── Lock Failure ─────────────────────────────────

    #[Test]
    public function it_throws_lock_exception_when_products_are_locked(): void
    {
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

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->with([1])
            ->once()
            ->andReturn(true);

        // InventoryService throws InsufficientStockException
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andThrow(InsufficientStockException::forProduct(1, 10, 5));

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

        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(true);

        // InventoryService returns items with snapshotted prices
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andReturn([
                new OrderItem(productId: 1, quantity: 3, unitPrice: '9.99'),
            ]);

        $this->inventoryService
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

        $this->orderCreatedHandler
            ->shouldReceive('handle')
            ->once();

        $this->lock->shouldReceive('releaseAll')->once();

        $result = $this->useCase->execute($dto);

        // 3 × 9.99 = 29.97 (bcmath precision, NOT 29.970000000000002)
        $this->assertSame('29.97', $result->order->totalAmount);
    }

    // ─── Lock Release on Exception ────────────────────

    #[Test]
    public function it_always_releases_locks_even_on_exception(): void
    {
        $this->lock
            ->shouldReceive('acquireForProducts')
            ->once()
            ->andReturn(true);

        // InventoryService throws DomainException for missing product
        $this->inventoryService
            ->shouldReceive('validateAndPrepareItems')
            ->once()
            ->andThrow(new \DomainException('Product not found: 999'));

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
