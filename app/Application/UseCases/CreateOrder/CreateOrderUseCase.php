<?php

declare(strict_types=1);

namespace App\Application\UseCases\CreateOrder;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\DTOs\CreateOrderResult;
use App\Application\DTOs\OrderResponseDTO;
use App\Application\EventHandlers\OrderCreatedHandler;
use App\Application\Idempotency\CreateOrderRequestFingerprint;
use App\Domain\Inventory\Contracts\DistributedLockInterface;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LockAcquisitionException;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Order\Contracts\IdempotencyRepositoryInterface;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\ValueObjects\IdempotencyRecord;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private IdempotencyRepositoryInterface $idempotencyRepository,
        private DistributedLockInterface $lock,
        private InventoryService $inventoryService,
        private OrderCreatedHandler $orderCreatedHandler,
    ) {}

    public function execute(CreateOrderDTO $dto): CreateOrderResult
    {
        $traceId = request()?->header('X-Trace-Id') ?? Str::uuid()->toString();
        $requestFingerprint = CreateOrderRequestFingerprint::fromDTO($dto);

        Log::info('CreateOrder started', [
            'user_id' => $dto->userId,
            'idempotency_key' => $dto->idempotencyKey,
            'trace_id' => $traceId,
        ]);

        // Fast replay path. The transaction below remains the authority for races.
        $existingRequest = $this->idempotencyRepository->find($dto->idempotencyKey);
        if ($existingRequest !== null) {
            $existingOrder = $this->completedOrder($existingRequest, $requestFingerprint);

            Log::info('Idempotent replay — returning durable result', [
                'order_id' => $existingOrder->getId(),
                'idempotency_key' => $dto->idempotencyKey,
                'trace_id' => $traceId,
                'event' => 'idempotency_replay',
            ]);

            return CreateOrderResult::replayed(OrderResponseDTO::fromEntity($existingOrder));
        }

        // Existing deployments may contain orders created before the durable
        // idempotency table was introduced, and external/older writers may
        // still rely on the orders unique index. Replay those rows before any
        // inventory validation or mutation.
        $legacyOrder = $this->orderRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($legacyOrder !== null) {
            $storedFingerprint = $legacyOrder->getRequestFingerprint()
                ?? CreateOrderRequestFingerprint::fromOrder($legacyOrder);
            $this->assertFingerprintMatches(
                $dto->idempotencyKey,
                $storedFingerprint,
                $requestFingerprint
            );

            Log::info('Idempotent replay resolved via orders unique index', [
                'order_id' => $legacyOrder->getId(),
                'idempotency_key' => $dto->idempotencyKey,
                'trace_id' => $traceId,
                'event' => 'legacy_idempotency_replay',
            ]);

            return CreateOrderResult::replayed(
                OrderResponseDTO::fromEntity($legacyOrder)
            );
        }

        $productIds = array_map(fn ($item) => $item['product_id'], $dto->items);
        $lockAcquired = false;

        try {
            // The idempotency reservation and business state commit atomically.
            try {
                $decision = DB::transaction(function () use (
                    $dto,
                    $traceId,
                    $requestFingerprint,
                    $productIds,
                    &$lockAcquired
                ) {
                    $reservation = $this->idempotencyRepository->reserve(
                        $dto->idempotencyKey,
                        $requestFingerprint
                    );

                    $this->assertFingerprintMatches(
                        $dto->idempotencyKey,
                        $reservation->record->requestFingerprint,
                        $requestFingerprint
                    );

                    if (! $reservation->ownedByCaller) {
                        return [
                            'order' => $this->completedOrder(
                                $reservation->record,
                                $requestFingerprint
                            ),
                            'created' => false,
                        ];
                    }

                    // The DB idempotency row is the first concurrency gate.
                    // A duplicate waits on that row and replays without being
                    // rejected by an incidental product-lock contention.
                    if (! $lockAcquired) {
                        if (! $this->lock->acquireForProducts($productIds)) {
                            throw new LockAcquisitionException($productIds);
                        }

                        $lockAcquired = true;
                    }

                    try {
                        $orderItems = $this->inventoryService->validateAndPrepareItems($dto->items);
                    } catch (InsufficientStockException $stockFailure) {
                        // A compatible legacy/external writer locks and consumes
                        // inventory before inserting its order. Once its commit
                        // releases the product row, the apparent stock failure
                        // must be reconciled against the authoritative orders
                        // unique index. A locking read is used as a current read;
                        // correctness does not depend on missing-row gap locks.
                        $concurrentLegacyOrder = $this->orderRepository
                            ->findByIdempotencyKeyForUpdate($dto->idempotencyKey);
                        if ($concurrentLegacyOrder === null) {
                            throw $stockFailure;
                        }

                        $storedFingerprint = $concurrentLegacyOrder->getRequestFingerprint()
                            ?? CreateOrderRequestFingerprint::fromOrder($concurrentLegacyOrder);
                        $this->assertFingerprintMatches(
                            $dto->idempotencyKey,
                            $storedFingerprint,
                            $requestFingerprint
                        );
                        $this->idempotencyRepository->complete(
                            $dto->idempotencyKey,
                            $concurrentLegacyOrder->getId()
                        );

                        Log::info('Stock failure reconciled as an idempotent replay', [
                            'order_id' => $concurrentLegacyOrder->getId(),
                            'idempotency_key' => $dto->idempotencyKey,
                            'trace_id' => $traceId,
                            'event' => 'legacy_idempotency_race_resolved',
                        ]);

                        return ['order' => $concurrentLegacyOrder, 'created' => false];
                    }

                    $this->inventoryService->decrementStock($orderItems);
                    $totalAmount = Order::calculateTotal($orderItems);
                    $order = new Order(
                        id: null,
                        userId: $dto->userId,
                        status: OrderStatus::PENDING,
                        totalAmount: $totalAmount,
                        idempotencyKey: $dto->idempotencyKey,
                        requestFingerprint: $requestFingerprint,
                    );
                    $order->setItems($orderItems);
                    $order = $this->orderRepository->save($order);
                    $this->idempotencyRepository->complete(
                        $dto->idempotencyKey,
                        $order->getId()
                    );

                    Log::info('Order created', [
                        'order_id' => $order->getId(),
                        'total' => $totalAmount,
                        'trace_id' => $traceId,
                        'event' => 'order_created',
                    ]);

                    return ['order' => $order, 'created' => true];
                }, attempts: 3);
            } catch (QueryException $e) {
                // Legacy/external writers may bypass idempotency_requests. The
                // existing orders unique index remains the final DB backstop.
                if ($this->isDuplicateIdempotencyKey($e)) {
                    $existing = $this->orderRepository->findByIdempotencyKey($dto->idempotencyKey);

                    if ($existing !== null) {
                        $storedFingerprint = $existing->getRequestFingerprint()
                            ?? CreateOrderRequestFingerprint::fromOrder($existing);
                        $this->assertFingerprintMatches(
                            $dto->idempotencyKey,
                            $storedFingerprint,
                            $requestFingerprint
                        );

                        Log::info('Idempotency race resolved via unique index', [
                            'order_id' => $existing->getId(),
                            'idempotency_key' => $dto->idempotencyKey,
                            'trace_id' => $traceId,
                            'event' => 'idempotency_race_resolved',
                        ]);

                        return CreateOrderResult::replayed(
                            OrderResponseDTO::fromEntity($existing)
                        );
                    }
                }

                throw $e;
            }

            /** @var Order $order */
            $order = $decision['order'];

            if (! $decision['created']) {
                return CreateOrderResult::replayed(OrderResponseDTO::fromEntity($order));
            }

            // Only the creator dispatches asynchronous processing.
            ProcessOrderJob::dispatch($order->getId(), $traceId)
                ->afterCommit();

            $this->orderCreatedHandler->handle(
                $order->getId(),
                $order->getUserId(),
                $order->getTotalAmount(),
                $traceId,
            );

            Log::info('ProcessOrderJob dispatched', [
                'order_id' => $order->getId(),
                'trace_id' => $traceId,
            ]);

            return CreateOrderResult::created(OrderResponseDTO::fromEntity($order));

        } finally {
            if ($lockAcquired) {
                $this->lock->releaseAll();
            }
        }
    }

    /**
     * MySQL duplicate-entry (error 1062, SQLSTATE 23000) on the orders
     * idempotency unique index.
     */
    private function isDuplicateIdempotencyKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'idx_orders_idempotency');
    }

    private function completedOrder(
        IdempotencyRecord $record,
        string $requestFingerprint
    ): Order {
        $this->assertFingerprintMatches(
            $record->idempotencyKey,
            $record->requestFingerprint,
            $requestFingerprint
        );

        if (! $record->isCompleted()) {
            throw new \RuntimeException(
                "Idempotency request '{$record->idempotencyKey}' is not durably completed."
            );
        }

        $order = $this->orderRepository->findById($record->orderId);
        if ($order === null) {
            throw new \RuntimeException(
                "Completed idempotency request '{$record->idempotencyKey}' has no order."
            );
        }

        return $order;
    }

    private function assertFingerprintMatches(
        string $idempotencyKey,
        string $storedFingerprint,
        string $requestFingerprint
    ): void {
        if (! hash_equals($storedFingerprint, $requestFingerprint)) {
            throw new IdempotencyConflictException($idempotencyKey);
        }
    }
}
