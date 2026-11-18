<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    #[Test]
    #[DataProvider('validTransitionProvider')]
    public function it_allows_valid_transitions(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    public static function validTransitionProvider(): array
    {
        return [
            'PENDING → PROCESSING' => [OrderStatus::PENDING, OrderStatus::PROCESSING],
            'PENDING → CANCELLED' => [OrderStatus::PENDING, OrderStatus::CANCELLED],
            'PROCESSING → PAID' => [OrderStatus::PROCESSING, OrderStatus::PAID],
            'PROCESSING → FAILED' => [OrderStatus::PROCESSING, OrderStatus::FAILED],
            'PAID → REFUNDED' => [OrderStatus::PAID, OrderStatus::REFUNDED],
        ];
    }

    #[Test]
    #[DataProvider('invalidTransitionProvider')]
    public function it_blocks_invalid_transitions(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'PENDING → PAID' => [OrderStatus::PENDING, OrderStatus::PAID],
            'PENDING → FAILED' => [OrderStatus::PENDING, OrderStatus::FAILED],
            'PROCESSING → PENDING' => [OrderStatus::PROCESSING, OrderStatus::PENDING],
            'PROCESSING → CANCELLED' => [OrderStatus::PROCESSING, OrderStatus::CANCELLED],
            'PAID → PENDING' => [OrderStatus::PAID, OrderStatus::PENDING],
            'PAID → FAILED' => [OrderStatus::PAID, OrderStatus::FAILED],
            'PAID → CANCELLED' => [OrderStatus::PAID, OrderStatus::CANCELLED],
            'FAILED → PENDING' => [OrderStatus::FAILED, OrderStatus::PENDING],
            'FAILED → PAID' => [OrderStatus::FAILED, OrderStatus::PAID],
            'CANCELLED → PENDING' => [OrderStatus::CANCELLED, OrderStatus::PENDING],
            'CANCELLED → PROCESSING' => [OrderStatus::CANCELLED, OrderStatus::PROCESSING],
            'CANCELLED → REFUNDED' => [OrderStatus::CANCELLED, OrderStatus::REFUNDED],
            'FAILED → REFUNDED' => [OrderStatus::FAILED, OrderStatus::REFUNDED],
            'PENDING → REFUNDED' => [OrderStatus::PENDING, OrderStatus::REFUNDED],
            'REFUNDED → PAID' => [OrderStatus::REFUNDED, OrderStatus::PAID],
            'REFUNDED → CANCELLED' => [OrderStatus::REFUNDED, OrderStatus::CANCELLED],
        ];
    }

    #[Test]
    #[DataProvider('terminalStateProvider')]
    public function terminal_states_are_correct(OrderStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $status->isTerminal());
    }

    public static function terminalStateProvider(): array
    {
        return [
            'PENDING is not terminal' => [OrderStatus::PENDING, false],
            'PROCESSING is not terminal' => [OrderStatus::PROCESSING, false],
            'PAID is not terminal (it can be refunded)' => [OrderStatus::PAID, false],
            'FAILED is terminal' => [OrderStatus::FAILED, true],
            'CANCELLED is terminal' => [OrderStatus::CANCELLED, true],
            'REFUNDED is terminal' => [OrderStatus::REFUNDED, true],
        ];
    }
}
