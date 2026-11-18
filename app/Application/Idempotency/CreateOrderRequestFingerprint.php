<?php

declare(strict_types=1);

namespace App\Application\Idempotency;

use App\Application\DTOs\CreateOrderDTO;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\ValueObjects\OrderItem;

final class CreateOrderRequestFingerprint
{
    public static function fromDTO(CreateOrderDTO $dto): string
    {
        return self::hash($dto->userId, $dto->items);
    }

    public static function fromOrder(Order $order): string
    {
        return self::hash(
            $order->getUserId(),
            array_map(
                fn (OrderItem $item) => [
                    'product_id' => $item->getProductId(),
                    'quantity' => $item->getQuantity(),
                ],
                $order->getItems()
            )
        );
    }

    /**
     * @param  array<array{product_id: int, quantity: int}>  $items
     */
    private static function hash(int $userId, array $items): string
    {
        $normalizedItems = array_map(
            fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ],
            $items
        );

        usort(
            $normalizedItems,
            fn (array $left, array $right) => [$left['product_id'], $left['quantity']]
                <=> [$right['product_id'], $right['quantity']]
        );

        $canonical = json_encode([
            'version' => 1,
            'user_id' => $userId,
            'items' => $normalizedItems,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical);
    }
}
