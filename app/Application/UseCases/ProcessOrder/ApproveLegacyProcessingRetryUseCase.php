<?php

declare(strict_types=1);

namespace App\Application\UseCases\ProcessOrder;

use App\Domain\Order\Contracts\OrderRepositoryInterface;

final class ApproveLegacyProcessingRetryUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orders,
    ) {}

    public function execute(int $orderId): bool
    {
        return $this->orders->markLegacyProcessingSafeToRecover($orderId);
    }
}
