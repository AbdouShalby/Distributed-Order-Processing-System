<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ProcessingRecoveryState;
use App\Domain\Order\ValueObjects\ProcessingClaim;
use App\Infrastructure\Persistence\Mappers\OrderMapper;
use App\Models\Order as OrderModel;
use App\Models\OrderItem as OrderItemModel;
use Illuminate\Support\Facades\DB;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function findById(int $id): ?Order
    {
        $model = OrderModel::with('items')->find($id);

        if (! $model) {
            return null;
        }

        return OrderMapper::toEntity($model);
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        $model = OrderModel::with('items')
            ->where('idempotency_key', $key)
            ->first();

        if (! $model) {
            return null;
        }

        return OrderMapper::toEntity($model);
    }

    public function findByIdempotencyKeyForUpdate(string $key): ?Order
    {
        $model = OrderModel::with('items')
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        return $model ? OrderMapper::toEntity($model) : null;
    }

    public function save(Order $order): Order
    {
        $model = OrderModel::create(
            OrderMapper::toModelAttributes($order)
        );

        $order->setId($model->id);

        // Bulk insert order items
        $itemRows = OrderMapper::itemsToModelAttributes($model->id, $order->getItems());
        foreach ($itemRows as $row) {
            OrderItemModel::create($row);
        }

        return $order;
    }

    public function updateStatus(Order $order): void
    {
        OrderModel::where('id', $order->getId())
            ->update([
                'status' => $order->getStatus()->value,
                'cancelled_at' => $order->getCancelledAt()?->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ]);
    }
    public function claimForProcessing(
        int $orderId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $leaseUntil,
        string $token
    ): ?ProcessingClaim {
        $paymentIdempotencyKey = "order-payment:v1:{$orderId}";

        $claimed = OrderModel::whereKey($orderId)
            ->where(function ($query) use ($claimedAt) {
                $query->where('status', OrderStatus::PENDING->value)
                    ->orWhere(function ($expired) use ($claimedAt) {
                        $expired->where('status', OrderStatus::PROCESSING->value)
                            ->where(
                                'processing_recovery_state',
                                ProcessingRecoveryState::LEASED->value
                            )
                            ->whereNotNull('processing_token')
                            ->whereNotNull('processing_claimed_at')
                            ->whereNotNull('processing_lease_until')
                            ->where('processing_lease_until', '<=', $claimedAt)
                            ->where('processing_attempt', '>', 0)
                            ->whereNotNull('payment_idempotency_key');
                    });
            })
            ->update([
                'status' => OrderStatus::PROCESSING->value,
                'processing_token' => $token,
                'processing_claimed_at' => $claimedAt,
                'processing_lease_until' => $leaseUntil,
                'processing_attempt' => DB::raw('processing_attempt + 1'),
                'payment_idempotency_key' => DB::raw(
                    "COALESCE(payment_idempotency_key, '{$paymentIdempotencyKey}')"
                ),
                'processing_recovery_state' => ProcessingRecoveryState::LEASED->value,
                'updated_at' => $claimedAt,
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $model = OrderModel::with('items')->findOrFail($orderId);

        return new ProcessingClaim(
            order: OrderMapper::toEntity($model),
            token: $token,
            claimedAt: new \DateTimeImmutable($model->processing_claimed_at->toIso8601String()),
            leaseUntil: new \DateTimeImmutable($model->processing_lease_until->toIso8601String()),
            attempt: (int) $model->processing_attempt,
            paymentIdempotencyKey: $model->payment_idempotency_key,
        );
    }

    public function completeProcessing(
        int $orderId,
        string $token,
        OrderStatus $finalStatus
    ): bool {
        if (! in_array($finalStatus, [OrderStatus::PAID, OrderStatus::FAILED], true)) {
            throw new \InvalidArgumentException('Processing may only complete as PAID or FAILED.');
        }

        return OrderModel::whereKey($orderId)
            ->where('status', OrderStatus::PROCESSING->value)
            ->where('processing_token', $token)
            ->update([
                'status' => $finalStatus->value,
                'processing_token' => null,
                'processing_lease_until' => null,
                'processing_recovery_state' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function expireProcessingLease(
        int $orderId,
        string $token,
        \DateTimeImmutable $expiredAt
    ): bool {
        return OrderModel::whereKey($orderId)
            ->where('status', OrderStatus::PROCESSING->value)
            ->where('processing_token', $token)
            ->update([
                'processing_lease_until' => $expiredAt,
                'updated_at' => $expiredAt,
            ]) === 1;
    }

    public function claimForCancellation(
        int $orderId,
        \DateTimeImmutable $cancelledAt
    ): bool {
        return OrderModel::whereKey($orderId)
            ->where('status', OrderStatus::PENDING->value)
            ->update([
                'status' => OrderStatus::CANCELLED->value,
                'cancelled_at' => $cancelledAt,
                'updated_at' => $cancelledAt,
            ]) === 1;
    }

    public function findByIdForUpdate(int $id): ?Order
    {
        $model = OrderModel::with('items')->lockForUpdate()->find($id);

        return $model ? OrderMapper::toEntity($model) : null;
    }


    public function findByUserId(int $userId, ?string $status = null, int $page = 1, int $perPage = 15): array
    {
        $query = OrderModel::with('items')->where('user_id', $userId);

        if ($status) {
            $query->where('status', $status);
        }

        $models = $query
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $models->map(fn (OrderModel $m) => OrderMapper::toEntity($m))->all();
    }

    public function countByUserId(int $userId, ?string $status = null): int
    {
        $query = OrderModel::where('user_id', $userId);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->count();
    }
}
