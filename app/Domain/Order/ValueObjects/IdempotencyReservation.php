<?php

declare(strict_types=1);

namespace App\Domain\Order\ValueObjects;

final readonly class IdempotencyReservation
{
    public function __construct(
        public bool $ownedByCaller,
        public IdempotencyRecord $record,
    ) {}
}
