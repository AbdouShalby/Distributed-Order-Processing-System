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
}
