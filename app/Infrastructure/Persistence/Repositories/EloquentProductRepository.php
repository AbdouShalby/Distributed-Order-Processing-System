<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Infrastructure\Persistence\Mappers\ProductMapper;
use App\Models\Product;

class EloquentProductRepository implements ProductRepositoryInterface
{
    public function findByIdForUpdate(int $id): ?array
    {
        $product = Product::where('id', $id)
            ->lockForUpdate()
            ->first();

        return $product ? ProductMapper::toArray($product) : null;
    }

    public function findById(int $id): ?array
    {
        $product = Product::find($id);

        return $product ? ProductMapper::toArray($product) : null;
    }

    public function decrementStock(int $productId, int $quantity): void
    {
        Product::where('id', $productId)->decrement('stock', $quantity);
    }

    public function incrementStock(int $productId, int $quantity): void
    {
        Product::where('id', $productId)->increment('stock', $quantity);
    }

    public function findAll(): array
    {
        return ProductMapper::collectionToArray(Product::all());
    }
}
