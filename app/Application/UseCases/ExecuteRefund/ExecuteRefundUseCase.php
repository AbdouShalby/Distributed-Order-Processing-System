<?php

declare(strict_types=1);

namespace App\Application\UseCases\ExecuteRefund;

use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ExecuteRefundUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private RefundRepositoryInterface $refunds,
        private PaymentGatewayInterface $gateway,
    ) {}

    public function execute(int $refundId): Refund
    {
        $refund = Refund::query()->find($refundId);
        if ($refund === null) {
            throw new OrderNotFoundException($refundId);
        }

        if ($refund->status !== RefundStatus::PENDING) {
            return $refund;
        }

        try {
            $result = $this->gateway->refund($refund->order_id, $refund->amount, $refund->provider_idempotency_key);
        } catch (\Throwable $e) {
            Log::warning('Refund gateway outcome is unknown.', [
                'trace_id' => $refund->trace_id,
                'refund_id' => $refund->id,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return $refund;
        }

        return DB::transaction(function () use ($refund, $refundId, $result): Refund {
            $order = $this->orders->findByIdForUpdate($refund->order_id);
            if ($order === null) {
                throw new OrderNotFoundException($refund->order_id);
            }

            $locked = $this->refunds->lockById($refundId);
            if ($locked === null) {
                throw new OrderNotFoundException($refundId);
            }

            if ($locked->status !== RefundStatus::PENDING) {
                return $locked;
            }

            $locked->status = $result->isSuccessful() ? RefundStatus::SUCCEEDED : RefundStatus::FAILED;
            $locked->failure_message = $result->isSuccessful() ? null : $result->getMessage();
            $this->refunds->save($locked);

            if ($result->isSuccessful()
                && bccomp(RefundAmounts::succeeded($this->refunds->forOrder($refund->order_id)), $order->getTotalAmount(), 2) === 0) {
                $this->orders->markRefundedIfPaid($refund->order_id);
            }

            return $locked;
        }, attempts: 3);
    }
}
