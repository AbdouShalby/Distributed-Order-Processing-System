<?php

declare(strict_types=1);

namespace App\Domain\Order\ValueObjects;

final readonly class IdempotencyRecord
{
    public function __construct(
        public string $idempotencyKey,
        public string $requestFingerprint,
        public string $status,
        public ?int $orderId,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED' && $this->orderId !== null;
    }
}
