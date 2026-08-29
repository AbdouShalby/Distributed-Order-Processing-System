<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Order\Contracts\IdempotencyRepositoryInterface;
use App\Domain\Order\ValueObjects\IdempotencyRecord;
use App\Domain\Order\ValueObjects\IdempotencyReservation;

final class InMemoryIdempotencyRepository implements IdempotencyRepositoryInterface
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function find(string $idempotencyKey): ?IdempotencyRecord
    {
        return $this->records[$idempotencyKey] ?? null;
    }

    public function reserve(string $idempotencyKey, string $requestFingerprint): IdempotencyReservation
    {
        $existing = $this->find($idempotencyKey);
        if ($existing !== null) {
            return new IdempotencyReservation(false, $existing);
        }

        $record = new IdempotencyRecord(
            $idempotencyKey,
            $requestFingerprint,
            'PROCESSING',
            null
        );
        $this->records[$idempotencyKey] = $record;

        return new IdempotencyReservation(true, $record);
    }

    public function complete(string $idempotencyKey, int $orderId): void
    {
        $record = $this->records[$idempotencyKey];
        $this->records[$idempotencyKey] = new IdempotencyRecord(
            $record->idempotencyKey,
            $record->requestFingerprint,
            'COMPLETED',
            $orderId
        );
    }

    public function seedCompleted(
        string $idempotencyKey,
        string $requestFingerprint,
        int $orderId
    ): void {
        $this->records[$idempotencyKey] = new IdempotencyRecord(
            $idempotencyKey,
            $requestFingerprint,
            'COMPLETED',
            $orderId
        );
    }
}
