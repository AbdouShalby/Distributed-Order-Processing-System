<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Application\DTOs\RefundResponseDTO;
use App\Application\UseCases\CreateRefund\CreateRefundUseCase;
use App\Application\UseCases\DecideRefund\DecideRefundUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateRefundRequest;
use App\Http\Requests\DecideRefundRequest;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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

    public function approve(DecideRefundRequest $request, int $id, DecideRefundUseCase $decideRefund): JsonResponse
    {
        $refund = $decideRefund->approve($id, $request->validated('decided_by'));

        return response()->json(['data' => RefundResponseDTO::fromModel($refund)->toArray()]);
    }

    public function reject(DecideRefundRequest $request, int $id, DecideRefundUseCase $decideRefund): JsonResponse
    {
        $refund = $decideRefund->reject($id, $request->validated('decided_by'), $request->validated('reason'));

        return response()->json(['data' => RefundResponseDTO::fromModel($refund)->toArray()]);
    }

    public function queue(Request $request): JsonResponse
    {
        // Validate the original query so global trimming cannot accept a different status.
        parse_str($request->getQueryString() ?? '', $query);
        $validated = Validator::make($query, ['status' => ['required', 'string', 'in:PENDING_APPROVAL,PENDING']])->validate();

        return response()->json([
            'data' => array_map(
                fn (Refund $refund): array => RefundResponseDTO::fromModel($refund)->toArray(),
                $this->refunds->withStatus(RefundStatus::from($validated['status']), 50),
            ),
        ]);
    }

    public function forCustomer(Request $request, string $customerId): JsonResponse
    {
        $validated = Validator::make(['customerId' => $customerId, 'since' => $request->query('since')], [
            'customerId' => ['required', 'string', 'regex:/\A[1-9]\d*\z/'],
            'since' => ['required', 'string', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?\z/'],
        ])->validate();
        $since = CarbonImmutable::parse($validated['since'])->setTimezone(config('app.timezone'));

        return response()->json([
            'data' => array_map(
                fn (Refund $refund): array => RefundResponseDTO::fromModel($refund)->toArray(),
                $this->refunds->forCustomerSince((int) $customerId, $since),
            ),
        ]);
    }
}
