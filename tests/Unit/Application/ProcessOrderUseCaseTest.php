<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\UseCases\ProcessOrder\ProcessOrderUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\ValueObjects\PaymentResult;
use App\Infrastructure\Broadcasting\Events\OrderFailedEvent;
use App\Infrastructure\Broadcasting\Events\OrderPaidEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private ProcessOrderUseCase $useCase;
    private OrderRepositoryInterface $orderRepo;
    private PaymentGatewayInterface $paymentGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->paymentGateway = $this->mock(PaymentGatewayInterface::class);

        $this->useCase = new ProcessOrderUseCase(
            $this->orderRepo,
            $this->paymentGateway,
        );
    }

    private function makePendingOrder(): Order
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PENDING,
            totalAmount: '100.00',
            idempotencyKey: 'key-123',
        );
        $order->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '50.00'),
        ]);

        return $order;
    }

    // ─── Payment Success ──────────────────────────────

    #[Test]
    public function it_processes_pending_order_and_marks_paid_on_success(): void
    {
        Event::fake([OrderPaidEvent::class]);

        $order = $this->makePendingOrder();

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        // First updateStatus: PROCESSING
        // Second updateStatus: PAID
        $this->orderRepo
            ->shouldReceive('updateStatus')
            ->twice();

        $this->paymentGateway
            ->shouldReceive('charge')
            ->with(1, '100.00')
            ->once()
            ->andReturn(PaymentResult::successful());

        $this->useCase->execute(1);

        $this->assertSame(OrderStatus::PAID, $order->getStatus());

        Event::assertDispatched(OrderPaidEvent::class, function ($event) {
            return $event->orderId === '1'
                && $event->userId === '10'
                && $event->total === '100.00';
        });
    }

    // ─── Payment Failure ──────────────────────────────

    #[Test]
    public function it_marks_order_failed_when_payment_fails(): void
    {
        Event::fake([OrderFailedEvent::class]);

        $order = $this->makePendingOrder();

        $this->orderRepo
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($order);

        $this->orderRepo
            ->shouldReceive('updateStatus')
            ->twice();

        $this->paymentGateway
            ->shouldReceive('charge')
            ->with(1, '100.00')
            ->once()
            ->andReturn(PaymentResult::failed('Insufficient funds'));

        $this->useCase->execute(1);

        $this->assertSame(OrderStatus::FAILED, $order->getStatus());

        Event::assertDispatched(OrderFailedEvent::class, function ($event) {
            return $event->orderId === '1'
                && $event->reason === 'Insufficient funds';
        });
    }

    // ─── Idempotency Guard ────────────────────────────

    #[Test]
    public function it_skips_processing_if_order_is_not_pending(): void
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

        // Should NOT call payment or update status
        $this->paymentGateway->shouldNotReceive('charge');
        $this->orderRepo->shouldNotReceive('updateStatus');

        $this->useCase->execute(1);

        // Status unchanged
        $this->assertSame(OrderStatus::PAID, $order->getStatus());
    }

    #[Test]
    public function it_skips_processing_if_order_already_processing(): void
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

        $this->paymentGateway->shouldNotReceive('charge');
        $this->orderRepo->shouldNotReceive('updateStatus');

        $this->useCase->execute(1);
    }

    // ─── Missing Order ────────────────────────────────

    #[Test]
    public function it_does_nothing_when_order_not_found(): void
    {
        $this->orderRepo
            ->shouldReceive('findById')
            ->with(999)
            ->once()
            ->andReturnNull();

        $this->paymentGateway->shouldNotReceive('charge');
        $this->orderRepo->shouldNotReceive('updateStatus');

        // No exception thrown — graceful no-op
        $this->useCase->execute(999);
    }

    // ─── State Machine Transition ─────────────────────

    #[Test]
    public function it_transitions_through_processing_before_final_state(): void
    {
        Event::fake();

        $order = $this->makePendingOrder();
        $statusTransitions = [];

        $this->orderRepo
            ->shouldReceive('findById')
            ->andReturn($order);

        $this->orderRepo
            ->shouldReceive('updateStatus')
            ->twice()
            ->andReturnUsing(function (Order $o) use (&$statusTransitions) {
                $statusTransitions[] = $o->getStatus()->value;
            });

        $this->paymentGateway
            ->shouldReceive('charge')
            ->andReturn(PaymentResult::successful());

        $this->useCase->execute(1);

        // Must transition PENDING → PROCESSING → PAID (in order)
        $this->assertSame(['PROCESSING', 'PAID'], $statusTransitions);
    }
}
