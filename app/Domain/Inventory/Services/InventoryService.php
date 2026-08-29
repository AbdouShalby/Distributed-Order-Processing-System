<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Order\ValueObjects\OrderItem;

/**
 * Domain service for inventory operations.
 *
 * Pure business logic — no Laravel, no Eloquent, no Redis.
 * This service is called by use cases to validate and manage stock.
 */
class InventoryService
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * Validate that all products have sufficient stock.
     * Must be called inside a DB transaction with SELECT FOR UPDATE.
     *
     * @param  array<array{product_id: int, quantity: int}>  $items
     * @return OrderItem[] Built order items with snapshotted prices
     *
     * @throws InsufficientStockException
     */
    public function validateAndPrepareItems(array $items): array
    {
        $orderItems = [];

        foreach ($items as $item) {
            $product = $this->productRepository->findByIdForUpdate($item['product_id']);

            if (! $product) {
                throw new \DomainException("Product not found: {$item['product_id']}");
            }

            if ($product['stock'] < $item['quantity']) {
                throw InsufficientStockException::forProduct(
                    $item['product_id'],
                    $item['quantity'],
                    (int) $product['stock']
                );
            }

            $orderItems[] = new OrderItem(
                productId: $item['product_id'],
                quantity: $item['quantity'],
                unitPrice: $product['price'],
            );
        }

        return $orderItems;
    }

    /**
     * Decrement stock for all items. Must be inside a DB transaction.
     *
     * @param  OrderItem[]  $items
     */
    public function decrementStock(array $items): void
    {
        foreach ($items as $item) {
            $this->productRepository->decrementStock(
                $item->getProductId(),
                $item->getQuantity()
            );
        }
    }

    /**
     * Restore stock for all items (used in cancellation).
     * Must be inside a DB transaction.
     *
     * @param  OrderItem[]  $items
     */
    public function restoreStock(array $items): void
    {
        foreach ($items as $item) {
            $this->productRepository->incrementStock(
                $item->getProductId(),
                $item->getQuantity()
            );
        }
    }
}
