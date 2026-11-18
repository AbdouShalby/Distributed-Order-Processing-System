<?php

declare(strict_types=1);

namespace App\Domain\Refund\Exceptions;

final class RefundExceedsPaidException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Refund exceeds the paid order total.');
    }
}
