<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;

final class EloquentRefundRepository implements RefundRepositoryInterface
{
    public function findByKey(string $key): ?Refund
    {
        return Refund::query()->where('idempotency_key', $key)->first();
    }

    public function forOrderLocked(int $orderId): array
    {
        return Refund::query()->where('order_id', $orderId)->lockForUpdate()->get()->all();
    }

    public function forOrder(int $orderId): array
    {
        return Refund::query()
            ->where('order_id', $orderId)
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function findForCustomer(int $refundId, int $customerId): ?Refund
    {
        return Refund::query()->whereKey($refundId)->where('customer_id', $customerId)->first();
    }

    public function create(array $attributes): Refund
    {
        return Refund::query()->create($attributes);
    }

    public function lockById(int $id): ?Refund
    {
        return Refund::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function save(Refund $refund): void
    {
        $refund->save();
    }

    public function pendingApproval(int $limit): array
    {
        return Refund::query()
            ->where('status', RefundStatus::PENDING_APPROVAL->value)
            ->oldest()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function forCustomerSince(int $customerId, \DateTimeInterface $since): array
    {
        return Refund::query()
            ->where('customer_id', $customerId)
            ->where('created_at', '>=', $since)
            ->latest()
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
