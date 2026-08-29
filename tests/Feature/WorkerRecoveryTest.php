<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\UseCases\ProcessOrder\ProcessOrderOutcome;
use App\Application\UseCases\ProcessOrder\ProcessOrderUseCase;
use App\Application\UseCases\ProcessOrder\RecoverExpiredProcessingOrdersUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Payment\Exceptions\PaymentGatewayUnavailableException;
use App\Domain\Payment\Exceptions\PaymentOutcomeUnknownException;
use App\Infrastructure\Broadcasting\Events\OrderFailedEvent;
use App\Infrastructure\Broadcasting\Events\OrderPaidEvent;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkerRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Event::fake([OrderPaidEvent::class, OrderFailedEvent::class]);
        config([
            'services.simulated_payment.force_outcome' => 'success',
            'services.simulated_payment.fail_before_persist' => false,
            'services.simulated_payment.timeout_after_persist' => false,
            'services.simulated_payment.lease_seconds' => 30,
        ]);
    }

    #[Test]
    public function duplicate_delivery_after_success_does_not_repeat_payment(): void
    {
        $orderId = $this->createPendingOrder('worker-duplicate');
        $useCase = app(ProcessOrderUseCase::class);

        $this->assertSame(ProcessOrderOutcome::PAID, $useCase->execute($orderId));
        $this->assertSame(ProcessOrderOutcome::SKIPPED, $useCase->execute($orderId));

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'PAID']);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'idempotency_key' => "order-payment:v1:{$orderId}",
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);
    }

    #[Test]
    public function a_live_lease_blocks_reclaim_and_an_expired_lease_allows_it(): void
    {
        $orderId = $this->createPendingOrder('worker-lease');
        $repository = app(OrderRepositoryInterface::class);
        $now = new \DateTimeImmutable('2026-08-27T10:00:00+00:00');

        $first = $repository->claimForProcessing(
            $orderId,
            $now,
            $now->modify('+30 seconds'),
            'claim-one'
        );
        $blocked = $repository->claimForProcessing(
            $orderId,
            $now->modify('+1 second'),
            $now->modify('+31 seconds'),
            'claim-two'
        );

        $this->assertNotNull($first);
        $this->assertNull($blocked);

        DB::table('orders')->where('id', $orderId)->update([
            'processing_lease_until' => $now->modify('-1 second'),
        ]);

        $recovered = $repository->claimForProcessing(
            $orderId,
            $now,
            $now->modify('+30 seconds'),
            'claim-three'
        );

        $this->assertNotNull($recovered);
        $this->assertSame(2, $recovered->attempt);
        $this->assertSame(
            $first->paymentIdempotencyKey,
            $recovered->paymentIdempotencyKey
        );
    }

    #[Test]
    public function ambiguous_timeout_reconciles_with_the_same_provider_identity(): void
    {
        $orderId = $this->createPendingOrder('worker-timeout');
        $useCase = app(ProcessOrderUseCase::class);
        config(['services.simulated_payment.timeout_after_persist' => true]);

        try {
            $useCase->execute($orderId);
            $this->fail('Expected an ambiguous provider timeout.');
        } catch (PaymentOutcomeUnknownException) {
            // The provider operation exists, but the order result is not durable yet.
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'PROCESSING']);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);

        config(['services.simulated_payment.timeout_after_persist' => false]);
        $this->assertSame(ProcessOrderOutcome::PAID, $useCase->execute($orderId));

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PAID',
            'processing_attempt' => 2,
        ]);
        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'request_count' => 2,
            'side_effect_count' => 1,
        ]);
    }

    #[Test]
    public function failure_before_provider_acceptance_can_retry_without_duplicate_side_effect(): void
    {
        $orderId = $this->createPendingOrder('worker-transient');
        $useCase = app(ProcessOrderUseCase::class);
        config(['services.simulated_payment.fail_before_persist' => true]);

        try {
            $useCase->execute($orderId);
            $this->fail('Expected provider unavailability.');
        } catch (PaymentGatewayUnavailableException) {
            // No provider operation was accepted.
        }

        $this->assertDatabaseCount('simulated_payment_operations', 0);

        config(['services.simulated_payment.fail_before_persist' => false]);
        $this->assertSame(ProcessOrderOutcome::PAID, $useCase->execute($orderId));

        $this->assertDatabaseHas('simulated_payment_operations', [
            'order_id' => $orderId,
            'request_count' => 1,
            'side_effect_count' => 1,
        ]);
    }

    #[Test]
    public function expired_processing_leases_are_requeued_by_the_recovery_sweep(): void
    {
        $expiredOrderId = $this->createPendingOrder('worker-expired-sweep');
        $liveOrderId = $this->createPendingOrder('worker-live-sweep');
        $repository = app(OrderRepositoryInterface::class);
        $now = new \DateTimeImmutable;

        $repository->claimForProcessing(
            $expiredOrderId,
            $now->modify('-2 minutes'),
            $now->modify('-1 minute'),
            'expired-sweep-token'
        );
        $repository->claimForProcessing(
            $liveOrderId,
            $now,
            $now->modify('+1 minute'),
            'live-sweep-token'
        );
        Queue::fake();

        $count = app(RecoverExpiredProcessingOrdersUseCase::class)->execute();

        $this->assertSame(1, $count);
        Queue::assertPushed(
            ProcessOrderJob::class,
            fn (ProcessOrderJob $job) => $job->orderId === $expiredOrderId
        );
        Queue::assertNotPushed(
            ProcessOrderJob::class,
            fn (ProcessOrderJob $job) => $job->orderId === $liveOrderId
        );
    }

    private function createPendingOrder(string $idempotencyKey): int
    {
        Queue::fake();

        return (int) $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => $idempotencyKey,
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data.id');
    }
}
