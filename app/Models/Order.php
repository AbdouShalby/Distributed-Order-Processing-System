<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ProcessingRecoveryState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes (whitelist).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'idempotency_key',
        'request_fingerprint',
        'processing_token',
        'processing_claimed_at',
        'processing_lease_until',
        'processing_attempt',
        'payment_idempotency_key',
        'processing_recovery_state',
        'cancelled_at',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'processing_claimed_at' => 'datetime',
            'processing_lease_until' => 'datetime',
            'processing_attempt' => 'integer',
            'processing_recovery_state' => ProcessingRecoveryState::class,
        ];
    }

    // ─── Relationships ────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // ─── Scopes ───────────────────────────────────────

    /**
     * Scope: orders with a given status.
     */
    public function scopeWithStatus($query, OrderStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope: non-terminal (active) orders.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            OrderStatus::PENDING->value,
            OrderStatus::PROCESSING->value,
        ]);
    }
}
