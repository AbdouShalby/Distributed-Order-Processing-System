<?php

declare(strict_types=1);

namespace App\Application\Refunds;

use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;

final class RefundDecision
{
    public static function status(string $mode, string $reserved, string $amount, ?string $autoApproveLimit): RefundStatus
    {
        return $mode === 'auto' && RefundAmounts::withinAutoLimit(bcadd($reserved, $amount, 2), $autoApproveLimit)
            ? RefundStatus::PENDING
            : RefundStatus::PENDING_APPROVAL;
    }
}
