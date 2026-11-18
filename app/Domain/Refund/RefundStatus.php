<?php

declare(strict_types=1);

namespace App\Domain\Refund;

enum RefundStatus: string
{
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case REJECTED = 'REJECTED';

    /**
     * @return list<self>
     */
    public static function reserving(): array
    {
        return [self::PENDING_APPROVAL, self::PENDING, self::SUCCEEDED];
    }
}
