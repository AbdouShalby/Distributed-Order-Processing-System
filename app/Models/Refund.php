<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Refund\RefundStatus;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'customer_id',
        'amount',
        'reason',
        'mode',
        'status',
        'idempotency_key',
        'request_fingerprint',
        'provider_idempotency_key',
        'trace_id',
        'decided_by',
        'decision_reason',
        'decided_at',
        'failure_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'status' => RefundStatus::class,
        ];
    }
}
