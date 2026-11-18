<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Refund;

use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundAmountsTest extends TestCase
{
    #[Test]
    #[DataProvider('validAmounts')]
    public function normalises_to_scale_two(string $amount, string $expected): void
    {
        $this->assertSame($expected, RefundAmounts::normalise($amount));
    }

    public static function validAmounts(): array
    {
        return [
            ['30', '30.00'],
            ['30.5', '30.50'],
            ['30.50', '30.50'],
            ['0', '0.00'],
            ['00030.05', '30.05'],
            ['99999999.99', '99999999.99'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function rejects_invalid_amounts(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RefundAmounts::normalise($amount);
    }

    public static function invalidAmounts(): array
    {
        return array_map(static fn (string $amount): array => [$amount], [
            '', 'abc', '-1', '+1', '1.234', '1e3', '100000000', '.50', '1.', ' 10', "10\n",
        ]);
    }

    #[Test]
    public function reserved_counts_pending_approval_pending_and_succeeded_only(): void
    {
        $this->assertSame([
            RefundStatus::PENDING_APPROVAL,
            RefundStatus::PENDING,
            RefundStatus::SUCCEEDED,
        ], RefundStatus::reserving());

        $refunds = [
            ['amount' => '10.01', 'status' => 'PENDING_APPROVAL'],
            ['amount' => '20.02', 'status' => RefundStatus::PENDING],
            new Refund(['amount' => '30.03', 'status' => RefundStatus::SUCCEEDED]),
            ['amount' => '40.04', 'status' => 'FAILED'],
            new Refund(['amount' => '50.05', 'status' => RefundStatus::REJECTED]),
        ];

        $this->assertSame('60.06', RefundAmounts::reserved($refunds));
        $this->assertSame('30.03', RefundAmounts::succeeded($refunds));
    }

    #[Test]
    public function supports_empty_and_lazy_iterables(): void
    {
        $this->assertSame('0.00', RefundAmounts::reserved([]));
        $this->assertSame('0.00', RefundAmounts::succeeded([]));
        $refunds = static function (): \Generator {
            yield ['amount' => '0.10', 'status' => RefundStatus::PENDING];
            yield ['amount' => '0.20', 'status' => 'SUCCEEDED'];
        };

        $this->assertSame('0.30', RefundAmounts::reserved($refunds()));
        $this->assertSame('0.20', RefundAmounts::succeeded($refunds()));
    }

    #[Test]
    public function cap_allows_exact_total_and_rejects_one_cent_more(): void
    {
        $this->assertTrue(RefundAmounts::fitsCap('69.99', '30.01', '100.00'));
        $this->assertFalse(RefundAmounts::fitsCap('69.99', '30.02', '100.00'));
        $this->assertTrue(RefundAmounts::fitsCap('0.10', '0.20', '0.30'));
    }

    #[Test]
    #[DataProvider('invalidAutoLimits')]
    public function malformed_or_empty_auto_limit_never_allows_auto(?string $limit): void
    {
        $this->assertFalse(RefundAmounts::withinAutoLimit('0.00', $limit));
    }

    public static function invalidAutoLimits(): array
    {
        return array_map(static fn (?string $limit): array => [$limit], [
            null, '', 'abc', '-1', '1.234', '1e3', ' 50.00', "50.00\n",
        ]);
    }

    #[Test]
    public function auto_limit_includes_the_boundary_and_rejects_one_cent_more(): void
    {
        $this->assertTrue(RefundAmounts::withinAutoLimit('50.00', '50'));
        $this->assertFalse(RefundAmounts::withinAutoLimit('50.01', '50.00'));
        $this->assertTrue(RefundAmounts::withinAutoLimit('0.00', '0.00'));
        $this->assertTrue(RefundAmounts::withinAutoLimit('99999999.99', '100000000.00'));
    }
}
