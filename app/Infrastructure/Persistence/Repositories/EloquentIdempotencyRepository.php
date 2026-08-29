<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Order\Contracts\IdempotencyRepositoryInterface;
use App\Domain\Order\ValueObjects\IdempotencyRecord;
use App\Domain\Order\ValueObjects\IdempotencyReservation;
use Illuminate\Support\Facades\DB;

final class EloquentIdempotencyRepository implements IdempotencyRepositoryInterface
{
    public function find(string $idempotencyKey): ?IdempotencyRecord
    {
        $row = DB::table('idempotency_requests')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $row ? $this->toRecord($row) : null;
    }

    public function reserve(string $idempotencyKey, string $requestFingerprint): IdempotencyReservation
    {
        $now = now();
        $inserted = DB::table('idempotency_requests')->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $requestFingerprint,
            'status' => 'PROCESSING',
            'order_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;

        $row = DB::table('idempotency_requests')
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->firstOrFail();

        return new IdempotencyReservation($inserted, $this->toRecord($row));
    }

    public function complete(string $idempotencyKey, int $orderId): void
    {
        $updated = DB::table('idempotency_requests')
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'PROCESSING')
            ->update([
                'status' => 'COMPLETED',
                'order_id' => $orderId,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException("Unable to complete idempotency request '{$idempotencyKey}'.");
        }
    }

    private function toRecord(object $row): IdempotencyRecord
    {
        return new IdempotencyRecord(
            idempotencyKey: $row->idempotency_key,
            requestFingerprint: $row->request_fingerprint,
            status: $row->status,
            orderId: $row->order_id === null ? null : (int) $row->order_id,
        );
    }
}
