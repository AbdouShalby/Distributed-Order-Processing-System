<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Application\DTOs\OrderResponseDTO;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Entities\Order;
use App\Domain\Order\Exceptions\OrderNotFoundException;
use App\Domain\Refund\RefundAmounts;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentOrderController extends Controller
{
    public function __construct(private OrderRepositoryInterface $orders) {}

    public function index(Request $request): JsonResponse
    {
        $orders = $this->orders->listForCustomer((int) $request->header('X-Customer-Id'), 20);
        $refunds = Refund::query()
            ->whereIn('order_id', array_map(fn (Order $order) => $order->getId(), $orders))
            ->get()
            ->groupBy('order_id');

        return response()->json([
            'data' => array_map(
                fn (Order $order) => $this->orderData($order, $refunds->get($order->getId(), [])),
                $orders,
            ),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = $this->orders->findById($id);

        if ($order === null || $order->getUserId() !== (int) $request->header('X-Customer-Id')) {
            throw new OrderNotFoundException($id);
        }

        $refunds = Refund::query()->where('order_id', $id)->get();

        return response()->json(['data' => $this->orderData($order, $refunds)]);
    }

    private function orderData(Order $order, iterable $refunds): array
    {
        return OrderResponseDTO::fromEntity($order)->toArray() + [
            'refunded_amount' => RefundAmounts::succeeded($refunds),
            'reserved_amount' => RefundAmounts::reserved($refunds),
        ];
    }
}
