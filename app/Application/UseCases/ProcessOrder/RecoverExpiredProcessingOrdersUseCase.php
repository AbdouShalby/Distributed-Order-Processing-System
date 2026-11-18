<?php

declare(strict_types=1);

namespace App\Application\UseCases\ProcessOrder;

use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RecoverExpiredProcessingOrdersUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orders,
    ) {}

    public function execute(int $limit = 100): int
    {
        $orderIds = $this->orders->findRecoverableProcessingIds(
            new \DateTimeImmutable,
            max(1, min($limit, 1000))
        );

        foreach ($orderIds as $orderId) {
            ProcessOrderJob::dispatch(
                $orderId,
                'lease-recovery-'.Str::uuid()->toString()
            )->afterCommit();
        }

        if ($orderIds !== []) {
            Log::warning('Recoverable processing orders requeued', [
                'order_ids' => $orderIds,
                'count' => count($orderIds),
                'event' => 'order_processing_recovery',
            ]);
        }

        return count($orderIds);
    }
}
