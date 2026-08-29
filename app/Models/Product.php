<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes (whitelist).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'price',
        'stock',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
        ];
    }

    // ─── Relationships ────────────────────────────────

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // ─── Scopes ───────────────────────────────────────

    /**
     * Scope: products with stock > 0.
     */
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }
}
