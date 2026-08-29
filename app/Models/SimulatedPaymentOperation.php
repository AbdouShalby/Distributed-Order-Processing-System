<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SimulatedPaymentOperation extends Model
{
    protected $fillable = [
        'idempotency_key',
        'order_id',
        'amount',
        'status',
        'message',
        'request_count',
        'side_effect_count',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'request_count' => 'integer',
            'side_effect_count' => 'integer',
        ];
    }
}
