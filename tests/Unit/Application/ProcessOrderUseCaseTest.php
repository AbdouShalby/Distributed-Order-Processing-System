<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\EventHandlers\OrderFailedHandler;
use App\Application\EventHandlers\OrderPaidHandler;
use App\Application\UseCases\ProcessOrder\ProcessOrderOutcome;
use App\Application\UseCases\ProcessOrder\ProcessOrderUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\OrderItem;
use App\Domain\Order\ValueObjects\ProcessingClaim;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Exceptions\PaymentGatewayUnavailableException;
use App\Domain\Payment\Exceptions\PaymentOutcomeUnknownException;
use App\Domain\Payment\ValueObjects\PaymentResult;
use App\Infrastructure\Broadcasting\Events\OrderFailedEvent;
use App\Infrastructure\Broadcasting\Events\OrderPaidEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProcessOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private ProcessOrderUseCase $useCase;

    private OrderRepositoryInterface $orderRepo;

    private PaymentGatewayInterface $paymentGateway;

    private OrderPaidHandler $orderPaidHandler;

    private OrderFailedHandler $orderFailedHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepo = $this->mock(OrderRepositoryInterface::class);
        $this->paymentGateway = $this->mock(PaymentGatewayInterface::class);
        $this->orderPaidHandler = $this->mock(OrderPaidHandler::class);
        $this->orderFailedHandler = $this->mock(OrderFailedHandler::class);

        $this->useCase = new ProcessOrderUseCase(
            $this->orderRepo,
            $this->paymentGateway,
            $this->orderPaidHandler,
            $this->orderFailedHandler,
        );
    }

    #[Test]
    public function only_a_successful_claim_may_charge_and_mark_paid(): void
    {
        Event::fake([OrderPaidEvent::class]);
        $claim = $this->makeClaim();

        $this->expectClaim($claim);
        $this->paymentGateway
            ->shouldReceive('charge')
            ->once()
            ->with(1, '100.00', 'order-payment:v1:1')
            ->andReturn(PaymentResult::successful());
        $this->orderRepo
            ->shouldReceive('completeProcessing')
            ->once()
            ->with(1, 'claim-token-1', OrderStatus::PAID)
            ->andReturnTrue();
        $this->orderPaidHandler->shouldReceive('handle')->once();

        $outcome = $this->useCase->execute(1, 'trace-1');

        $this->assertSame(ProcessOrderOutcome::PAID, $outcome);
        Event::assertDispatched(OrderPaidEvent::class);
    }

    #[Test]
    public function a_provider_decline_is_a_durable_terminal_failure(): void
    {
        Event::fake([OrderFailedEvent::class]);
        $claim = $this->makeClaim();

        $this->expectClaim($claim);
        $this->paymentGateway
            ->shouldReceive('charge')
            ->once()
            ->with(1, '100.00', 'order-payment:v1:1')
            ->andReturn(PaymentResult::failed('Insufficient funds'));
        $this->orderRepo
            ->shouldReceive('completeProcessing')
            ->once()
            ->with(1, 'claim-token-1', OrderStatus::FAILED)
            ->andReturnTrue();
        $this->orderFailedHandler->shouldReceive('handle')->once();

        $outcome = $this->useCase->execute(1);

        $this->assertSame(ProcessOrderOutcome::FAILED, $outcome);
        Event::assertDispatched(OrderFailedEvent::class);
    }

    #[Test]
    public function duplicate_delivery_cannot_charge_without_a_claim(): void
    {
        $this->expectRejectedClaim();
        $this->paymentGateway->shouldNotReceive('charge');
        $this->orderRepo->shouldNotReceive('completeProcessing');

        $outcome = $this->useCase->execute(1);

        $this->assertSame(ProcessOrderOutcome::SKIPPED, $outcome);
    }

    #[Test]
    public function a_stale_worker_cannot_commit_over_a_newer_claim(): void
    {
        Event::fake();
        $claim = $this->makeClaim();

        $this->expectClaim($claim);
        $this->paymentGateway
            ->shouldReceive('charge')
            ->once()
            ->andReturn(PaymentResult::successful());
        $this->orderRepo
            ->shouldReceive('completeProcessing')
            ->once()
            ->with(1, 'claim-token-1', OrderStatus::PAID)
            ->andReturnFalse();
        $this->orderPaidHandler->shouldNotReceive('handle');
        $this->orderFailedHandler->shouldNotReceive('handle');

        $outcome = $this->useCase->execute(1);

        $this->assertSame(ProcessOrderOutcome::LOST_CLAIM, $outcome);
        Event::assertNotDispatched(OrderPaidEvent::class);
        Event::assertNotDispatched(OrderFailedEvent::class);
    }

    #[Test]
    public function failure_before_provider_acceptance_expires_the_lease_for_retry(): void
    {
        $claim = $this->makeClaim();
        $exception = new PaymentGatewayUnavailableException('offline');

        $this->expectClaim($claim);
        $this->paymentGateway
            ->shouldReceive('charge')
            ->once()
            ->with(1, '100.00', 'order-payment:v1:1')
            ->andThrow($exception);
        $this->expectLeaseExpiry();

        $this->expectExceptionObject($exception);
        $this->useCase->execute(1);
    }

    #[Test]
    public function ambiguous_provider_timeout_expires_the_lease_and_preserves_payment_identity(): void
    {
        $claim = $this->makeClaim();
        $exception = new PaymentOutcomeUnknownException('unknown');

        $this->expectClaim($claim);
        $this->paymentGateway
            ->shouldReceive('charge')
            ->once()
            ->with(1, '100.00', 'order-payment:v1:1')
            ->andThrow($exception);
        $this->expectLeaseExpiry();

        $this->expectExceptionObject($exception);
        $this->useCase->execute(1);
    }

    private function makeClaim(): ProcessingClaim
    {
        $order = new Order(
            id: 1,
            userId: 10,
            status: OrderStatus::PROCESSING,
            totalAmount: '100.00',
            idempotencyKey: 'request-key',
        );
        $order->setItems([
            new OrderItem(productId: 1, quantity: 2, unitPrice: '50.00'),
        ]);

        return new ProcessingClaim(
            order: $order,
            token: 'claim-token-1',
            claimedAt: new \DateTimeImmutable('2026-08-27T10:00:00+00:00'),
            leaseUntil: new \DateTimeImmutable('2026-08-27T10:00:30+00:00'),
            attempt: 1,
            paymentIdempotencyKey: 'order-payment:v1:1',
        );
    }

    private function expectClaim(ProcessingClaim $claim): void
    {
        $this->orderRepo
            ->shouldReceive('claimForProcessing')
            ->once()
            ->with(
                1,
                Mockery::type(\DateTimeImmutable::class),
                Mockery::type(\DateTimeImmutable::class),
                Mockery::type('string')
            )
            ->andReturn($claim);
    }

    private function expectRejectedClaim(): void
    {
        $this->orderRepo
            ->shouldReceive('claimForProcessing')
            ->once()
            ->andReturnNull();
    }

    private function expectLeaseExpiry(): void
    {
        $this->orderRepo
            ->shouldReceive('expireProcessingLease')
            ->once()
            ->with(1, 'claim-token-1', Mockery::type(\DateTimeImmutable::class))
            ->andReturnTrue();
    }
}
