<?php

declare(strict_types=1);

namespace App\Application\UseCases\CancelOrder;

use App\Application\DTOs\OrderResponseDTO;
use App\Application\EventHandlers\OrderCancelledHandler;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Infrastructure\Broadcasting\Events\OrderCancelledEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CancelOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private InventoryService $inventoryService,
        private OrderCancelledHandler $orderCancelledHandler,
    ) {}

    public function execute(int $orderId): OrderResponseDTO
    {
        $traceId = request()?->header('X-Trace-Id') ?? Str::uuid()->toString();
        $cancelledAt = new \DateTimeImmutable;

        $decision = DB::transaction(function () use ($orderId, $cancelledAt) {
            $claimed = $this->orderRepository->claimForCancellation(
                $orderId,
                $cancelledAt
            );
            $order = $this->orderRepository->findByIdForUpdate($orderId);

            if ($order === null) {
                throw new OrderNotFoundException($orderId);
            }

            if (! $claimed) {
                if ($order->getStatus() === OrderStatus::CANCELLED) {
                    Log::info('CancelOrder: already cancelled (no-op)', [
                        'order_id' => $orderId,
                    ]);

                    return ['order' => $order, 'cancelled' => false];
                }

                throw new OrderNotCancellableException($order->getStatus()->value);
            }

            $this->inventoryService->restoreStock($order->getItems());

            Log::info('Order cancelled + stock restored', [
                'order_id' => $order->getId(),
                'items_restored' => count($order->getItems()),
                'event' => 'order_cancelled',
            ]);

            return ['order' => $order, 'cancelled' => true];
        });

        /** @var \App\Domain\Order\Entities\Order $order */
        $order = $decision['order'];

        if (! $decision['cancelled']) {
            return OrderResponseDTO::fromEntity($order);
        }

        broadcast(new OrderCancelledEvent(
            orderId: (string) $order->getId(),
            userId: (string) $order->getUserId(),
            cancelledAt: $cancelledAt->format(DATE_ATOM),
        ));

        $this->orderCancelledHandler->handle(
            $orderId,
            $order->getUserId(),
            count($order->getItems()),
            $traceId,
        );

        $updatedOrder = $this->orderRepository->findById($orderId);

        return OrderResponseDTO::fromEntity($updatedOrder);
    }
}
