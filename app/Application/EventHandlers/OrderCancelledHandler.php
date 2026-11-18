<?php

declare(strict_types=1);

namespace App\Application\EventHandlers;

use Illuminate\Support\Facades\Log;

/**
 * Handles post-cancellation logic for cancelled orders.
 *
 * Called after an order is cancelled and stock is restored.
 */
class OrderCancelledHandler
{
    public function handle(int $orderId, int $userId, int $itemsRestored, string $traceId): void
    {
        Log::info('OrderCancelledHandler: cancellation processed', [
            'event' => 'order_cancelled_handled',
            'order_id' => $orderId,
            'user_id' => $userId,
            'items_restored' => $itemsRestored,
            'trace_id' => $traceId,
        ]);

        // Future hooks:
        // - Send cancellation confirmation
        // - Process refund if payment was captured
        // - Update cancellation analytics
        // - Check cancellation rate thresholds
    }
}
