<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

final class IdempotencyConflictException extends \DomainException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(
            "Idempotency key '{$idempotencyKey}' was already used with different request parameters."
        );
    }
}
