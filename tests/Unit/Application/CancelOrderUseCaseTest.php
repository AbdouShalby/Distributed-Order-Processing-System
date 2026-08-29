<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\EventHandlers\OrderCancelledHandler;
use App\Application\UseCases\CancelOrder\CancelOrderUseCase;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Infrastructure\Broadcasting\Events\OrderCancelledEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CancelOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private CancelOrderUseCase $useCase;

    private OrderRepositoryInterface $orderRepo;

    private InventoryService $inventoryService;

    private OrderCancelledHandler $orderCancelledHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->inventoryService = $this->mock(InventoryService::class);
        $this->orderCancelledHandler = $this->mock(OrderCancelledHandler::class);

        $this->useCase = new CancelOrderUseCase(
            $this->orderRepo,
            $this->inventoryService,
            $this->orderCancelledHandler,
        );
    }

    #[Test]
    public function only_the_successful_claim_restores_inventory(): void
    {
        Event::fake([OrderCancelledEvent::class]);
        $order = $this->cancelledOrder();

        $this->orderRepo
            ->shouldReceive('claimForCancellation')
            ->once()
            ->with(1, Mockery::type(\DateTimeImmutable::class))
            ->andReturnTrue();
        $this->orderRepo
            ->shouldReceive('findByIdForUpdate')
            ->once()
            ->with(1)
            ->andReturn($order);
        $this->inventoryService->shouldReceive('restoreStock')->once();
        $this->orderCancelledHandler->shouldReceive('handle')->once();
        $this->orderRepo
            ->shouldReceive('findById')
            ->once()
            ->with(1)
            ->andReturn($order);

        $response = $this->useCase->execute(1);

        $this->assertSame('CANCELLED', $response->status);
        Event::assertDispatched(OrderCancelledEvent::class);
    }

    #[Test]
    public function repeated_cancellation_is_a_side_effect_free_replay(): void
    {
        $order = $this->cancelledOrder();

        $this->orderRepo->shouldReceive('claimForCancellation')->once()->andReturnFalse();
        $this->orderRepo->shouldReceive('findByIdForUpdate')->once()->andReturn($order);
        $this->inventoryService->shouldNotReceive('restoreStock');
        $this->orderCancelledHandler->shouldNotReceive('handle');
        $this->orderRepo->shouldNotReceive('findById');

        $response = $this->useCase->execute(1);

        $this->assertSame('CANCELLED', $response->status);
    }

    #[Test]
    public function processing_order_cannot_be_cancelled(): void
    {
        $this->orderRepo->shouldReceive('claimForCancellation')->once()->andReturnFalse();
        $this->orderRepo
            ->shouldReceive('findByIdForUpdate')
            ->once()
            ->andReturn($this->orderWithStatus(OrderStatus::PROCESSING));

        $this->expectException(OrderNotCancellableException::class);
        $this->useCase->execute(1);
    }

    #[Test]
    public function missing_order_is_reported_after_the_failed_claim(): void
    {
        $this->orderRepo->shouldReceive('claimForCancellation')->once()->andReturnFalse();
        $this->orderRepo->shouldReceive('findByIdForUpdate')->once()->andReturnNull();

        $this->expectException(OrderNotFoundException::class);
        $this->useCase->execute(1);
    }

    #[Test]
    public function restoration_failure_does_not_publish_completion_side_effects(): void
    {
        Event::fake();
        $order = $this->cancelledOrder();

        $this->orderRepo->shouldReceive('claimForCancellation')->once()->andReturnTrue();
        $this->orderRepo->shouldReceive('findByIdForUpdate')->once()->andReturn($order);
        $this->inventoryService
            ->shouldReceive('restoreStock')
            ->once()
            ->andThrow(new \RuntimeException('restore failed'));
        $this->orderCancelledHandler->shouldNotReceive('handle');
        $this->orderRepo->shouldNotReceive('findById');

        $this->expectException(\RuntimeException::class);
        $this->useCase->execute(1);
    }

    private function cancelledOrder(): Order
    {
        return $this->orderWithStatus(OrderStatus::CANCELLED);
    }

    private function orderWithStatus(OrderStatus $status): Order
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: $status,
            totalAmount: '209.97',
            idempotencyKey: 'key-123',
            cancelledAt: $status === OrderStatus::CANCELLED
                ? new \DateTimeImmutable
                : null,
        );
        $order->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '29.99'),
            new OrderItem(productId: 3, quantity: 1, unitPrice: '149.99'),
        ]);

        return $order;
    }
}
