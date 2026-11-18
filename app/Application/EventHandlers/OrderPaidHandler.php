<?php

declare(strict_types=1);

namespace App\Application\EventHandlers;

use Illuminate\Support\Facades\Log;

/**
 * Handles post-payment logic for paid orders.
 *
 * Called after an order payment is successfully processed.
 */
class OrderPaidHandler
{
    public function handle(int $orderId, string $totalAmount, string $traceId): void
    {
        Log::info('OrderPaidHandler: payment confirmed', [
            'event' => 'order_paid_handled',
            'order_id' => $orderId,
            'total_amount' => $totalAmount,
            'trace_id' => $traceId,
        ]);

        // Future hooks:
        // - Send receipt email
        // - Update sales analytics
        // - Trigger fulfillment workflow
    }
}
