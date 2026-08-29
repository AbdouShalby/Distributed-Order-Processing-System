<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Mappers;

use App\Models\Product as ProductModel;

/**
 * Maps between Eloquent Product model and array representation.
 *
 * Products don't have a Domain Entity (they're referenced by ID + price snapshot),
 * so this mapper converts to/from the array format used by ProductRepositoryInterface.
 */
class ProductMapper
{
    /**
     * Map an Eloquent Product model → array (as expected by repository interface).
     */
    public static function toArray(ProductModel $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'price' => (string) $model->price,
            'stock' => (int) $model->stock,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Map an array of Eloquent Product models → array of arrays.
     *
     * @param  iterable<ProductModel>  $models
     * @return array[]
     */
    public static function collectionToArray(iterable $models): array
    {
        $result = [];
        foreach ($models as $model) {
            $result[] = self::toArray($model);
        }

        return $result;
    }
}
