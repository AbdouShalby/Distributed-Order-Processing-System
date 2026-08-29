<?php

declare(strict_types=1);

namespace App\Domain\Order\Contracts;

use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\ProcessingClaim;

interface OrderRepositoryInterface
{
    public function findById(int $id): ?Order;

    public function findByIdempotencyKey(string $key): ?Order;

    public function findByIdempotencyKeyForUpdate(string $key): ?Order;

    public function save(Order $order): Order;

    public function updateStatus(Order $order): void;

    public function claimForProcessing(
        int $orderId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $leaseUntil,
        string $token
    ): ?ProcessingClaim;

    public function completeProcessing(
        int $orderId,
        string $token,
        OrderStatus $finalStatus
    ): bool;

    public function expireProcessingLease(
        int $orderId,
        string $token,
        \DateTimeImmutable $expiredAt
    ): bool;

    /** @return int[] */
    public function findRecoverableProcessingIds(
        \DateTimeImmutable $asOf,
        int $limit
    ): array;

    public function markLegacyProcessingSafeToRecover(int $orderId): bool;

    public function claimForCancellation(
        int $orderId,
        \DateTimeImmutable $cancelledAt
    ): bool;

    public function findByIdForUpdate(int $id): ?Order;

    /**
     * @return Order[]
     */
    public function findByUserId(int $userId, ?string $status = null, int $page = 1, int $perPage = 15): array;

    public function countByUserId(int $userId, ?string $status = null): int;
}
