<?php

declare(strict_types=1);

namespace App\Application\EventHandlers;

use Illuminate\Support\Facades\Log;

/**
 * Handles post-failure logic for failed orders.
 *
 * Called when a payment attempt for an order fails permanently.
 */
class OrderFailedHandler
{
    public function handle(int $orderId, string $reason, string $traceId): void
    {
        Log::warning('OrderFailedHandler: payment failure processed', [
            'event' => 'order_failed_handled',
            'order_id' => $orderId,
            'reason' => $reason,
            'trace_id' => $traceId,
        ]);

        // Future hooks:
        // - Notify user about payment failure
        // - Auto-retry with different payment method
        // - Restore stock if payment is terminal
        // - Create support ticket
    }
}
