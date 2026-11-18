<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\DTOs\CreateOrderDTO;
use App\Application\DTOs\OrderResponseDTO;
use App\Application\UseCases\CancelOrder\CancelOrderUseCase;
use App\Application\UseCases\CreateOrder\CreateOrderUseCase;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\LockAcquisitionException;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Order\Exceptions\OrderNotCancellableException;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Http\Requests\CreateOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private CreateOrderUseCase $createOrderUseCase,
        private CancelOrderUseCase $cancelOrderUseCase,
        private OrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * POST /api/orders
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $dto = new CreateOrderDTO(
                userId: $request->validated('user_id'),
                items: $request->validated('items'),
                idempotencyKey: $request->validated('idempotency_key'),
            );

            $result = $this->createOrderUseCase->execute($dto);

            return response()
                ->json(
                    [
                        'data' => $result->order->toArray(),
                        'meta' => ['idempotency_replayed' => ! $result->isCreated()],
                    ],
                    $result->isCreated() ? 201 : 200
                )
                ->header(
                    'Idempotency-Replayed',
                    $result->isCreated() ? 'false' : 'true'
                );

        } catch (LockAcquisitionException $e) {
            return response()->json([
                'message' => 'Could not acquire lock. Please retry.',
                'error_code' => 'LOCK_CONFLICT',
            ], 409);

        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'insufficient_stock',
            ], 409);

        } catch (IdempotencyConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'IDEMPOTENCY_CONFLICT',
            ], 409);

        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'DOMAIN_ERROR',
            ], 422);
        }
    }

    /**
     * GET /api/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $order = $this->orderRepository->findById($id);

        if (! $order) {
            return response()->json([
                'message' => 'Order not found.',
                'error_code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'data' => OrderResponseDTO::fromEntity($order)->toArray(),
        ]);
    }

    /**
     * GET /api/orders?user_id=&status=&page=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'status' => ['nullable', 'string', 'in:PENDING,PROCESSING,PAID,FAILED,CANCELLED,REFUNDED'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $userId = (int) $request->input('user_id');
        $status = $request->input('status');
        $page = (int) ($request->input('page', 1));
        $perPage = (int) ($request->input('per_page', 15));

        $orders = $this->orderRepository->findByUserId($userId, $status, $page, $perPage);
        $total = $this->orderRepository->countByUserId($userId, $status);

        return response()->json([
            'data' => array_map(
                fn ($order) => OrderResponseDTO::fromEntity($order)->toArray(),
                $orders
            ),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/orders/{id}/cancel
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $response = $this->cancelOrderUseCase->execute($id);

            return response()->json([
                'data' => $response->toArray(),
            ]);

        } catch (OrderNotFoundException $e) {
            return response()->json([
                'message' => 'Order not found.',
                'error_code' => 'NOT_FOUND',
            ], 404);

        } catch (OrderNotCancellableException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'INVALID_TRANSITION',
            ], 422);
        }
    }
}
