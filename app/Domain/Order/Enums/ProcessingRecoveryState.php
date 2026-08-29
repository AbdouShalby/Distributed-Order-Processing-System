<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

enum ProcessingRecoveryState: string
{
    case LEASED = 'LEASED';
}
