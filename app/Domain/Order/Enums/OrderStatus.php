<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';

    /**
     * Check if transition to a new status is allowed.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::PENDING => in_array($newStatus, [self::PROCESSING, self::CANCELLED]),
            self::PROCESSING => in_array($newStatus, [self::PAID, self::FAILED]),
            self::PAID => $newStatus === self::REFUNDED,
            self::FAILED, self::CANCELLED, self::REFUNDED => false,
        };
    }

    /**
     * Terminal states — no further transitions possible.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::FAILED, self::CANCELLED, self::REFUNDED]);
    }
}
