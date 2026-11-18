<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Models\Refund;

final readonly class RefundResponseDTO
{
    private function __construct(private array $data) {}

    public static function fromModel(Refund $refund): self
    {
        return new self([
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'amount' => $refund->amount,
            'reason' => $refund->reason,
            'mode' => $refund->mode,
            'status' => $refund->status->value,
            'decided_by' => $refund->decided_by,
            'decision_reason' => $refund->decision_reason,
            'failure_message' => $refund->failure_message,
            'trace_id' => $refund->trace_id,
            'created_at' => $refund->created_at?->toIso8601String(),
            'updated_at' => $refund->updated_at?->toIso8601String(),
        ]);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
