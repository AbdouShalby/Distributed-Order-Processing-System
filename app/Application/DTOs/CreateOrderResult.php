<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateOrderResult
{
    private function __construct(
        public OrderResponseDTO $order,
        public bool $created,
    ) {}

    public static function created(OrderResponseDTO $order): self
    {
        return new self($order, true);
    }

    public static function replayed(OrderResponseDTO $order): self
    {
        return new self($order, false);
    }

    public function isCreated(): bool
    {
        return $this->created;
    }
}
