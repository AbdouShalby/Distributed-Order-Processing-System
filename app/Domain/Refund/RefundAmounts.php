<?php

declare(strict_types=1);

namespace App\Domain\Refund;

final class RefundAmounts
{
    public static function normalise(string $amount): string
    {
        if (preg_match('/\A\d{1,8}(\.\d{1,2})?\z/', $amount) !== 1) {
            throw new \InvalidArgumentException('Invalid refund amount.');
        }

        return bcadd($amount, '0', 2);
    }

    public static function reserved(iterable $refunds): string
    {
        return self::sum($refunds, RefundStatus::reserving());
    }

    public static function succeeded(iterable $refunds): string
    {
        return self::sum($refunds, [RefundStatus::SUCCEEDED]);
    }

    public static function fitsCap(string $reserved, string $amount, string $total): bool
    {
        return bccomp(bcadd($reserved, $amount, 2), $total, 2) <= 0;
    }

    public static function withinAutoLimit(string $reservedAfter, ?string $limit): bool
    {
        if ($limit === null || preg_match('/\A\d+(\.\d{1,2})?\z/', $limit) !== 1) {
            return false;
        }

        return bccomp($reservedAfter, $limit, 2) <= 0;
    }

    /**
     * @param  list<RefundStatus>  $statuses
     */
    private static function sum(iterable $refunds, array $statuses): string
    {
        $total = '0.00';

        foreach ($refunds as $refund) {
            $status = is_array($refund) ? $refund['status'] : $refund->status;
            $status = is_string($status) ? RefundStatus::tryFrom($status) : $status;

            if (in_array($status, $statuses, true)) {
                $amount = is_array($refund) ? $refund['amount'] : $refund->amount;
                $total = bcadd($total, $amount, 2);
            }
        }

        return $total;
    }
}
