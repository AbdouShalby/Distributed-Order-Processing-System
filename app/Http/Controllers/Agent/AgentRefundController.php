<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Application\DTOs\RefundResponseDTO;
use App\Application\UseCases\CreateRefund\CreateRefundUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRefundRequest;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentRefundController extends Controller
{
    public function __construct(
        private CreateRefundUseCase $createRefund,
        private OrderRepositoryInterface $orders,
        private RefundRepositoryInterface $refunds,
    ) {}

    public function store(CreateRefundRequest $request, int $id): JsonResponse
    {
        try {
            $result = $this->createRefund->execute(
                orderId: $id,
                customerId: (int) $request->header('X-Customer-Id'),
                amount: $request->validated('amount'),
                reason: $request->validated('reason'),
                idempotencyKey: $request->validated('idempotency_key'),
                mode: $request->validated('mode'),
                traceId: $request->header('X-Trace-Id'),
            );
        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        }

        return response()->json([
            'data' => RefundResponseDTO::fromModel($result['refund'])->toArray(),
        ], $result['created'] ? 201 : 200);
    }

    public function indexForOrder(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->findById($id);
        if ($order === null || $order->getUserId() !== (int) $request->header('X-Customer-Id')) {
            throw new OrderNotFoundException($id);
        }

        return response()->json([
            'data' => array_map(
                fn (Refund $refund): array => RefundResponseDTO::fromModel($refund)->toArray(),
                $this->refunds->forOrder($id),
            ),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $refund = $this->refunds->findForCustomer($id, (int) $request->header('X-Customer-Id'));
        if ($refund === null) {
            throw new OrderNotFoundException($id);
        }

        return response()->json(['data' => RefundResponseDTO::fromModel($refund)->toArray()]);
    }
}
