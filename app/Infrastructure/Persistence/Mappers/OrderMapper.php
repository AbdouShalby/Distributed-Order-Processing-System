<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mappers;

use App\Domain\Order\Entities\Order as OrderEntity;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\ValueObjects\OrderItem as OrderItemVO;
use App\Models\Order as OrderModel;
use App\Models\OrderItem as OrderItemModel;

/**
 * Maps between Eloquent Order model and Domain Order entity.
 *
 * This mapper ensures the Domain layer stays pure (no Eloquent dependency)
 * while the Infrastructure layer can use Eloquent models internally.
 */
class OrderMapper
{
    /**
     * Map an Eloquent Order model → Domain Order entity.
     */
    public static function toEntity(OrderModel $model): OrderEntity
    {
        $order = new OrderEntity(
            id: $model->id,
            userId: $model->user_id,
            status: $model->status instanceof OrderStatus
                ? $model->status
                : OrderStatus::from($model->status),
            totalAmount: (string) $model->total_amount,
            idempotencyKey: $model->idempotency_key,
            requestFingerprint: $model->request_fingerprint,
            cancelledAt: $model->cancelled_at
                ? new \DateTimeImmutable($model->cancelled_at->toIso8601String())
                : null,
            createdAt: $model->created_at
                ? new \DateTimeImmutable($model->created_at->toIso8601String())
                : null,
            updatedAt: $model->updated_at
                ? new \DateTimeImmutable($model->updated_at->toIso8601String())
                : null,
        );

        // Map items if loaded
        if ($model->relationLoaded('items')) {
            $items = $model->items->map(
                fn (OrderItemModel $item) => new OrderItemVO(
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    unitPrice: (string) $item->unit_price,
                )
            )->all();

            $order->setItems($items);
        }

        return $order;
    }

    /**
     * Map a Domain Order entity → associative array for Eloquent persistence.
     */
    public static function toModelAttributes(OrderEntity $entity): array
    {
        return [
            'user_id' => $entity->getUserId(),
            'status' => $entity->getStatus()->value,
            'total_amount' => $entity->getTotalAmount(),
            'idempotency_key' => $entity->getIdempotencyKey(),
            'request_fingerprint' => $entity->getRequestFingerprint(),
            'cancelled_at' => $entity->getCancelledAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Map Domain OrderItem value objects → array for bulk insert.
     *
     * @param  OrderItemVO[]  $items
     * @return array<int, array<string, mixed>>
     */
    public static function itemsToModelAttributes(int $orderId, array $items): array
    {
        return array_map(fn (OrderItemVO $item) => [
            'order_id' => $orderId,
            'product_id' => $item->getProductId(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $items);
    }
}
