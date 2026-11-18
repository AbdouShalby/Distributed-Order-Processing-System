<?php

declare(strict_types=1);

namespace App\Application\Refunds;

final class RefundFingerprint
{
    public static function of(int $orderId, int $customerId, string $normalisedAmount, string $mode): string
    {
        return hash('sha256', json_encode([
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'amount' => $normalisedAmount,
            'mode' => $mode,
        ], JSON_THROW_ON_ERROR));
    }
}
