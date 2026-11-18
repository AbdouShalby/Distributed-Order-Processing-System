<?php

declare(strict_types=1);

namespace App\Application\UseCases\ProcessOrder;

use App\Application\EventHandlers\OrderFailedHandler;
use App\Application\EventHandlers\OrderPaidHandler;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Infrastructure\Broadcasting\Events\OrderFailedEvent;
use App\Infrastructure\Broadcasting\Events\OrderPaidEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private PaymentGatewayInterface $paymentGateway,
        private OrderPaidHandler $orderPaidHandler,
        private OrderFailedHandler $orderFailedHandler,
    ) {}

    public function execute(int $orderId, ?string $traceId = null): ProcessOrderOutcome
    {
        $traceId ??= request()?->header('X-Trace-Id') ?? Str::uuid()->toString();
        $claimedAt = new \DateTimeImmutable;
        $leaseSeconds = max(
            1,
            (int) config('services.simulated_payment.lease_seconds', 30)
        );
        $leaseUntil = $claimedAt->modify("+{$leaseSeconds} seconds");
        $token = Str::uuid()->toString();

        $claim = $this->orderRepository->claimForProcessing(
            $orderId,
            $claimedAt,
            $leaseUntil,
            $token
        );

        if ($claim === null) {
            Log::info('ProcessOrder: claim rejected', [
                'order_id' => $orderId,
                'event' => 'order_processing_claim_rejected',
            ]);

            return ProcessOrderOutcome::SKIPPED;
        }

        Log::info('Order processing claimed', [
            'order_id' => $orderId,
            'claim_token' => $claim->token,
            'attempt' => $claim->attempt,
            'lease_until' => $claim->leaseUntil->format(DATE_ATOM),
            'event' => 'order_processing_claimed',
        ]);

        try {
            $result = $this->paymentGateway->charge(
                $orderId,
                $claim->order->getTotalAmount(),
                $claim->paymentIdempotencyKey
            );
        } catch (\Throwable $exception) {
            // The worker is no longer active. Expiring its lease allows a queue
            // retry to reclaim immediately while reusing the same payment key.
            $this->orderRepository->expireProcessingLease(
                $orderId,
                $claim->token,
                new \DateTimeImmutable
            );

            Log::warning('Payment attempt did not produce a durable order result', [
                'order_id' => $orderId,
                'provider_idempotency_key' => $claim->paymentIdempotencyKey,
                'exception' => $exception::class,
                'event' => 'payment.retryable_failure',
            ]);

            throw $exception;
        }

        $finalStatus = $result->isSuccessful()
            ? OrderStatus::PAID
            : OrderStatus::FAILED;
        $completed = $this->orderRepository->completeProcessing(
            $orderId,
            $claim->token,
            $finalStatus
        );

        if (! $completed) {
            Log::warning('Processing result ignored because the claim is stale', [
                'order_id' => $orderId,
                'claim_token' => $claim->token,
                'event' => 'order_processing_stale_claim',
            ]);

            return ProcessOrderOutcome::LOST_CLAIM;
        }

        if ($result->isSuccessful()) {
            Log::info('Order paid', [
                'order_id' => $orderId,
                'event' => 'order_paid',
            ]);

            broadcast(new OrderPaidEvent(
                orderId: (string) $orderId,
                userId: (string) $claim->order->getUserId(),
                total: $claim->order->getTotalAmount(),
                paidAt: now()->toIso8601String(),
            ));

            $this->orderPaidHandler->handle(
                $orderId,
                $claim->order->getTotalAmount(),
                $traceId,
            );

            return ProcessOrderOutcome::PAID;
        }

        Log::warning('Order payment declined', [
            'order_id' => $orderId,
            'reason' => $result->getMessage(),
            'event' => 'order_failed',
        ]);

        broadcast(new OrderFailedEvent(
            orderId: (string) $orderId,
            userId: (string) $claim->order->getUserId(),
            reason: $result->getMessage(),
            failedAt: now()->toIso8601String(),
        ));

        $this->orderFailedHandler->handle(
            $orderId,
            $result->getMessage(),
            $traceId,
        );

        return ProcessOrderOutcome::FAILED;
    }
}
