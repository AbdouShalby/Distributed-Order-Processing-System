<?php

declare(strict_types=1);

namespace App\Domain\Refund\Exceptions;

final class OrderNotRefundableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Only paid orders can be refunded.');
    }
}
