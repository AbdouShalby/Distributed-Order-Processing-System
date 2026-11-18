<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\ValueObjects\PaymentResult;

interface PaymentGatewayInterface
{
    /**
     * Charge one logical payment operation. Every retry must reuse the same
     * provider idempotency key.
     */
    public function charge(
        int $orderId,
        string $amount,
        string $providerIdempotencyKey
    ): PaymentResult;

    /**
     * Refund one logical operation. Every retry must reuse the same provider
     * idempotency key, which must not have been used for a charge.
     */
    public function refund(
        int $orderId,
        string $amount,
        string $providerIdempotencyKey
    ): PaymentResult;
}
