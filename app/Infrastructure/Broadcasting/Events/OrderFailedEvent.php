<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderFailedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $orderId,
        public readonly string $userId,
        public readonly string $reason,
        public readonly string $failedAt,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("orders.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.failed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'reason' => $this->reason,
            'failed_at' => $this->failedAt,
        ];
    }
}
