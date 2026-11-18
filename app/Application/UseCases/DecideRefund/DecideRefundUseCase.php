<?php

declare(strict_types=1);

namespace App\Application\UseCases\DecideRefund;

use App\Application\UseCases\ExecuteRefund\ExecuteRefundUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\Exceptions\RefundNotPendingApprovalException;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

final class DecideRefundUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private RefundRepositoryInterface $refunds,
        private ExecuteRefundUseCase $executeRefund,
    ) {}

    public function approve(int $refundId, string $decidedBy): Refund
    {
        $this->decide($refundId, $decidedBy, null);

        return $this->executeRefund->execute($refundId);
    }

    public function reject(int $refundId, string $decidedBy, string $reason): Refund
    {
        return $this->decide($refundId, $decidedBy, $reason);
    }

    private function decide(int $refundId, string $decidedBy, ?string $reason): Refund
    {
        $orderId = $this->refunds->orderIdOf($refundId);
        if ($orderId === null) {
            throw new OrderNotFoundException($refundId);
        }

        return DB::transaction(function () use ($refundId, $orderId, $decidedBy, $reason): Refund {
            $order = $this->orders->findByIdForUpdate($orderId);
            if ($order === null) {
                throw new OrderNotFoundException($orderId);
            }

            $refund = $this->refunds->lockById($refundId);
            if ($refund === null) {
                throw new OrderNotFoundException($refundId);
            }
            if ($refund->status !== RefundStatus::PENDING_APPROVAL) {
                throw new RefundNotPendingApprovalException;
            }

            $refund->status = $reason === null ? RefundStatus::PENDING : RefundStatus::REJECTED;
            $refund->decided_by = $decidedBy;
            $refund->decided_at = now();
            $refund->decision_reason = $reason;
            $this->refunds->save($refund);

            return $refund;
        });
    }
}
