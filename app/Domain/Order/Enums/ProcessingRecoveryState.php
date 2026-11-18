<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

enum ProcessingRecoveryState: string
{
    case LEASED = 'LEASED';
    case RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';
    case SAFE_TO_RECOVER = 'SAFE_TO_RECOVER';
}
