<?php

declare(strict_types=1);

namespace App\Infrastructure\PaymentGateway;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Exceptions\PaymentGatewayUnavailableException;
use App\Domain\Payment\Exceptions\PaymentOutcomeUnknownException;
use App\Domain\Payment\ValueObjects\PaymentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Simulated payment gateway.
 * 80% success rate, 20% failure rate.
 */
class SimulatedPaymentGateway implements PaymentGatewayInterface
{
    public function charge(
        int $orderId,
        string $amount,
        string $providerIdempotencyKey
    ): PaymentResult {
        if (config('services.simulated_payment.fail_before_persist')) {
            throw new PaymentGatewayUnavailableException(
                'Simulated provider unavailable before accepting the payment.'
            );
        }

        usleep(random_int(50_000, 200_000));

        $forcedOutcome = config('services.simulated_payment.force_outcome');
        $isSuccess = match ($forcedOutcome) {
            'success' => true,
            'decline' => false,
            null, '' => random_int(1, 100) <= 80,
            default => throw new \InvalidArgumentException(
                "Unsupported simulated payment outcome '{$forcedOutcome}'."
            ),
        };
        $status = $isSuccess ? 'SUCCEEDED' : 'DECLINED';
        $message = $isSuccess
            ? 'Payment processed successfully.'
            : 'Simulated payment decline.';

        // This table models the provider's durable idempotency ledger. The
        // unique key ensures concurrent retries create one logical side effect.
        $inserted = DB::table('simulated_payment_operations')->insertOrIgnore([
            'idempotency_key' => $providerIdempotencyKey,
            'order_id' => $orderId,
            'amount' => $amount,
            'status' => $status,
            'message' => $message,
            'request_count' => 0,
            'side_effect_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;

        DB::table('simulated_payment_operations')
            ->where('idempotency_key', $providerIdempotencyKey)
            ->increment('request_count');

        $operation = DB::table('simulated_payment_operations')
            ->where('idempotency_key', $providerIdempotencyKey)
            ->firstOrFail();

        if ((int) $operation->order_id !== $orderId
            || bccomp((string) $operation->amount, $amount, 2) !== 0) {
            throw new \DomainException(
                'Provider idempotency key was reused for a different payment operation.'
            );
        }

        Log::info($inserted ? 'Payment operation accepted' : 'Payment operation replayed', [
            'order_id' => $orderId,
            'provider_idempotency_key' => $providerIdempotencyKey,
            'status' => $operation->status,
            'event' => $inserted ? 'payment.accepted' : 'payment.replayed',
        ]);

        if ($inserted && config('services.simulated_payment.timeout_after_persist')) {
            throw new PaymentOutcomeUnknownException(
                'Simulated provider timed out after accepting the payment; outcome is unknown to the caller.'
            );
        }

        return $operation->status === 'SUCCEEDED'
            ? PaymentResult::successful()
            : PaymentResult::failed($operation->message);
    }
}
