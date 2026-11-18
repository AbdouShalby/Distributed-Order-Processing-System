<?php

declare(strict_types=1);

namespace App\Domain\Refund\Contracts;

use App\Models\Refund;

interface RefundRepositoryInterface
{
    public function findByKey(string $key): ?Refund;

    /**
     * Callers must already hold the order row lock in their transaction.
     *
     * @return list<Refund>
     */
    public function forOrderLocked(int $orderId): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Refund;

    public function lockById(int $id): ?Refund;

    public function save(Refund $refund): void;

    /**
     * @return list<Refund>
     */
    public function pendingApproval(int $limit): array;

    /**
     * @return list<Refund>
     */
    public function forCustomerSince(int $customerId, \DateTimeInterface $since): array;
}
