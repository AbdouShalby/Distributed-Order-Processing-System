<?php

declare(strict_types=1);

namespace App\Domain\Order\Entities;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\InvalidOrderTransitionException;
use App\Domain\Order\ValueObjects\OrderItem;

class Order
{
    /** @var OrderItem[] */
    private array $items = [];

    public function __construct(
        private ?int $id,
        private int $userId,
        private OrderStatus $status,
        private string $totalAmount,
        private string $idempotencyKey,
        private ?string $requestFingerprint = null,
        private ?\DateTimeImmutable $cancelledAt = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    // ─── Getters ──────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getRequestFingerprint(): ?string
    {
        return $this->requestFingerprint;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return OrderItem[] */
    public function getItems(): array
    {
        return $this->items;
    }

    // ─── Setters ──────────────────────────────────────

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    // ─── State Transitions ────────────────────────────

    public function markAsProcessing(): void
    {
        $this->transitionTo(OrderStatus::PROCESSING);
    }

    public function markAsPaid(): void
    {
        $this->transitionTo(OrderStatus::PAID);
    }

    public function markAsFailed(): void
    {
        $this->transitionTo(OrderStatus::FAILED);
    }

    public function markAsCancelled(): void
    {
        $this->transitionTo(OrderStatus::CANCELLED);
        $this->cancelledAt = new \DateTimeImmutable;
    }

    // ─── Business Logic ───────────────────────────────

    public function isCancellable(): bool
    {
        return $this->status === OrderStatus::PENDING;
    }

    public function isProcessable(): bool
    {
        return $this->status === OrderStatus::PENDING;
    }

    /**
     * Calculate total from items (server-side — never trust client).
     */
    public static function calculateTotal(array $items): string
    {
        $total = '0';
        foreach ($items as $item) {
            $lineTotal = bcmul($item->getUnitPrice(), (string) $item->getQuantity(), 2);
            $total = bcadd($total, $lineTotal, 2);
        }

        return $total;
    }

    // ─── Private ──────────────────────────────────────

    private function transitionTo(OrderStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new InvalidOrderTransitionException($this->status, $newStatus);
        }

        $this->status = $newStatus;
    }
}
