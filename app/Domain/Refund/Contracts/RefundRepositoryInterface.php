<?php

declare(strict_types=1);

namespace App\Domain\Refund\Contracts;

use App\Domain\Refund\RefundStatus;
use App\Models\Refund;

interface RefundRepositoryInterface
{
    public function findByKey(string $key): ?Refund;

    /**
     * Read-only, newest first; does not acquire row locks.
     * Inside a refund transaction, call only after acquiring the order row lock.
     *
     * @return list<Refund>
     */
    public function forOrder(int $orderId): array;

    public function findForCustomer(int $refundId, int $customerId): ?Refund;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Refund;

    public function lockById(int $id): ?Refund;

    public function orderIdOf(int $refundId): ?int;

    public function save(Refund $refund): void;

    /**
     * @return list<Refund>
     */
    public function withStatus(RefundStatus $status, int $limit): array;

    /**
     * @return list<Refund>
     */
    public function forCustomerSince(int $customerId, \DateTimeInterface $since): array;
}
