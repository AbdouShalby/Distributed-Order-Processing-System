<?php

declare(strict_types=1);

namespace App\Domain\Refund\Exceptions;

final class RefundNotPendingApprovalException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Only refunds pending approval can be approved or rejected.');
    }
}
