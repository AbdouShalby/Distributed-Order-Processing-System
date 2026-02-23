<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCases\CancelOrder\CancelOrderUseCase;
use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Infrastructure\Broadcasting\Events\OrderCancelledEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancelOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private CancelOrderUseCase $useCase;
    private OrderRepositoryInterface $orderRepo;
    private ProductRepositoryInterface $productRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->productRepo = $this->mock(ProductRepositoryInterface::class);

        $this->useCase = new CancelOrderUseCase(
            $this->orderRepo,
            $this->productRepo,
        );
    }

    private function makePendingOrderWithItems(): Order
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PENDING,
            totalAmount: '209.97',
            idempotencyKey: 'key-123',
        );
        $order->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        return $order;
    }

    // ─── Success Path ─────────────────────────────────

    #[Test]
    public function it_cancels_pending_order_and_restores_stock(): void
    {
        Event::fake([OrderCancelledEvent::class]);

        $order = $this->makePendingOrderWithItems();

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->twice() // once to load, once to reload after cancel
            ->andReturn($order);

        $this->orderRepo
            ->shouldReceive('updateStatus')
            ->once();

        // Stock must be restored for EACH item
        $this->productRepo
            ->shouldReceive('incrementStock')
            ->with(1, 2) // product 1, qty 2
            ->once();

        $this->productRepo
            ->shouldReceive('incrementStock')
            ->with(3, 1) // product 3, qty 1
            ->once();

        $response = $this->useCase->execute(1);

        $this->assertSame(OrderStatus::CANCELLED, $order->getStatus());
        $this->assertNotNull($order->getCancelledAt());
        $this->assertSame('209.97', $response->totalAmount);

        Event::assertDispatched(OrderCancelledEvent::class, function ($event) {
            return $event->orderId === '1' && $event->userId === '10';
        });
    }

    // ─── Idempotent Cancel ────────────────────────────

    #[Test]
    public function it_returns_success_when_order_already_cancelled(): void
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::CANCELLED,
            totalAmount: '100.00',
            idempotencyKey: 'key-123',
        );
        $order->setItems([]);

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        // Should NOT try to cancel again
        $this->orderRepo->shouldNotReceive('updateStatus');
        $this->productRepo->shouldNotReceive('incrementStock');

        $response = $this->useCase->execute(1);

        $this->assertSame(1, $response->id);
        $this->assertSame('CANCELLED', $response->status);
    }

    // ─── Non-Cancellable States ───────────────────────

    #[Test]
    public function it_throws_when_order_is_processing(): void
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PROCESSING,
            totalAmount: '100.00',
            idempotencyKey: 'key-123',
        );

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        $this->expectException(OrderNotCancellableException::class);

        $this->useCase->execute(1);
    }

    #[Test]
    public function it_throws_when_order_is_paid(): void
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PAID,
            totalAmount: '100.00',
            idempotencyKey: 'key-123',
        );

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        $this->expectException(OrderNotCancellableException::class);

        $this->useCase->execute(1);
    }

    #[Test]
    public function it_throws_when_order_is_failed(): void
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::FAILED,
            totalAmount: '100.00',
            idempotencyKey: 'key-123',
        );

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        $this->expectException(OrderNotCancellableException::class);

        $this->useCase->execute(1);
    }

    // ─── Order Not Found ──────────────────────────────

    #[Test]
    public function it_throws_when_order_does_not_exist(): void
    {
        $this->orderRepo
            ->shouldReceive('findById')
            ->with(999)
            ->once()
            ->andReturnNull();

        $this->expectException(OrderNotFoundException::class);

        $this->useCase->execute(999);
    }

    // ─── Stock Restoration Count ──────────────────────

    #[Test]
    public function it_restores_stock_for_all_items_in_order(): void
    {
        Event::fake();

        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PENDING,
            totalAmount: '500.00',
            idempotencyKey: 'key-multi',
        );
        $order->setItems([
            new OrderItem(productId: 1, quantity: 5, unitPrice: '10.00'),
            new OrderItem(productId: 2, quantity: 3, unitPrice: '50.00'),
            new OrderItem(productId: 3, quantity: 10, unitPrice: '20.00'),
        ]);

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->andReturn($order);

        $this->orderRepo
            ->shouldReceive('updateStatus')
            ->once();

        // Verify exact quantities restored for each product
        $this->productRepo
            ->shouldReceive('incrementStock')
            ->with(1, 5)
            ->once();

        $this->productRepo
            ->shouldReceive('incrementStock')
            ->with(2, 3)
            ->once();

        $this->productRepo
            ->shouldReceive('incrementStock')
            ->with(3, 10)
            ->once();

        $this->useCase->execute(1);
    }
}
