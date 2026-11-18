<?php

declare(strict_types=1);

namespace App\Domain\Order\ValueObjects;

use App\Domain\Order\Entities\Order;

final readonly class ProcessingClaim
{
    public function __construct(
        public Order $order,
        public string $token,
        public \DateTimeImmutable $claimedAt,
        public \DateTimeImmutable $leaseUntil,
        public int $attempt,
        public string $paymentIdempotencyKey,
    ) {}
}
