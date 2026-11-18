<?php

declare(strict_types=1);

namespace App\Application\UseCases\ProcessOrder;

enum ProcessOrderOutcome: string
{
    case PAID = 'paid';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
    case LOST_CLAIM = 'lost_claim';
}
