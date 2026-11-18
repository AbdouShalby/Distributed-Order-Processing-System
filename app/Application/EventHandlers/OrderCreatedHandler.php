<?php

declare(strict_types=1);

namespace App\Application\EventHandlers;

use Illuminate\Support\Facades\Log;

/**
 * Handles post-creation logic for new orders.
 *
 * Called after an order is successfully persisted and committed.
 * Logs the event with structured context for observability.
 */
class OrderCreatedHandler
{
    public function handle(int $orderId, int $userId, string $totalAmount, string $traceId): void
    {
        Log::info('OrderCreatedHandler: order created event processed', [
            'event' => 'order_created',
            'order_id' => $orderId,
            'user_id' => $userId,
            'total_amount' => $totalAmount,
            'trace_id' => $traceId,
        ]);

        // Future hooks:
        // - Send order confirmation notification
        // - Publish to external message broker (Kafka/RabbitMQ)
        // - Update analytics/reporting
        // - Trigger inventory alerts if stock is low
    }
}
