<?php

declare(strict_types=1);

namespace App\Domain\Order\Contracts;

use App\Domain\Order\ValueObjects\IdempotencyRecord;
use App\Domain\Order\ValueObjects\IdempotencyReservation;

interface IdempotencyRepositoryInterface
{
    public function find(string $idempotencyKey): ?IdempotencyRecord;

    /**
     * Reserve a key inside the caller's database transaction.
     */
    public function reserve(string $idempotencyKey, string $requestFingerprint): IdempotencyReservation;

    public function complete(string $idempotencyKey, int $orderId): void;
}
