<?php

declare(strict_types=1);

namespace App\Application\UseCases\CreateRefund;

use App\Application\Refunds\RefundDecision;
use App\Application\Refunds\RefundFingerprint;
use App\Application\UseCases\ExecuteRefund\ExecuteRefundUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\Exceptions\OrderNotRefundableException;
use App\Domain\Refund\Exceptions\RefundExceedsPaidException;
use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateRefundUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private RefundRepositoryInterface $refunds,
        private ExecuteRefundUseCase $executeRefund,
    ) {}

    /**
     * @return array{refund: Refund, created: bool}
     */
    public function execute(
        int $orderId,
        int $customerId,
        string $amount,
        string $reason,
        string $idempotencyKey,
        string $mode,
        ?string $traceId,
    ): array {
        $amount = RefundAmounts::normalise($amount);
        $fingerprint = RefundFingerprint::of($orderId, $customerId, $amount, $mode);

        try {
            $result = DB::transaction(function () use ($orderId, $customerId, $amount, $reason, $idempotencyKey, $mode, $traceId, $fingerprint): array {
                $order = $this->orders->lockForCustomer($orderId, $customerId);

                if ($order === null) {
                    throw new OrderNotFoundException($orderId);
                }

                $existing = $this->refunds->findByKey($idempotencyKey);
                if ($existing !== null) {
                    return $this->replay($existing, $fingerprint, $idempotencyKey);
                }

                if ($order->getStatus() !== OrderStatus::PAID) {
                    throw new OrderNotRefundableException;
                }

                $rows = $this->refunds->forOrder($orderId);
                $reserved = RefundAmounts::reserved($rows);
                if (! RefundAmounts::fitsCap($reserved, $amount, $order->getTotalAmount())) {
                    throw new RefundExceedsPaidException;
                }

                $limit = config('services.refunds.auto_approve_limit');
                $status = RefundDecision::status($mode, $reserved, $amount, is_string($limit) ? $limit : null);
                $refund = $this->refunds->create([
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'amount' => $amount,
                    'reason' => $reason,
                    'mode' => $mode,
                    'status' => $status,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'provider_idempotency_key' => 'refund-'.Str::uuid(),
                    'trace_id' => $traceId,
                ]);

                return ['refund' => $refund, 'created' => true];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // A competing order may have inserted the global key. Read only after rollback.
            $existing = $this->refunds->findByKey($idempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            $result = $this->replay($existing, $fingerprint, $idempotencyKey);
        }

        if ($result['refund']->status === RefundStatus::PENDING) {
            $result['refund'] = $this->executeRefund->execute($result['refund']->id);
        }

        return $result;
    }

    /**
     * @return array{refund: Refund, created: bool}
     */
    private function replay(Refund $refund, string $fingerprint, string $key): array
    {
        if ($refund->request_fingerprint !== $fingerprint) {
            throw new IdempotencyConflictException($key);
        }

        return ['refund' => $refund, 'created' => false];
    }
}
