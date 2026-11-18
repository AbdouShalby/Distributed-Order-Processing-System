<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Refunds\RefundDecision;
use App\Application\Refunds\RefundFingerprint;
use App\Domain\Refund\RefundAmounts;
use App\Domain\Refund\RefundStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateRefundDecisionTest extends TestCase
{
    #[Test]
    public function fingerprint_is_canonical_and_stable_for_normalised_amounts_without_reason(): void
    {
        $expected = hash('sha256', '{"order_id":12,"customer_id":7,"amount":"30.00","mode":"auto"}');

        $this->assertSame($expected, RefundFingerprint::of(12, 7, RefundAmounts::normalise('30'), 'auto'));
        $this->assertSame($expected, RefundFingerprint::of(12, 7, RefundAmounts::normalise('30.00'), 'auto'));
        $this->assertNotSame($expected, RefundFingerprint::of(13, 7, '30.00', 'auto'));
        $this->assertNotSame($expected, RefundFingerprint::of(12, 8, '30.00', 'auto'));
        $this->assertNotSame($expected, RefundFingerprint::of(12, 7, '30.01', 'auto'));
        $this->assertNotSame($expected, RefundFingerprint::of(12, 7, '30.00', 'needs_approval'));
    }

    #[Test]
    #[DataProvider('decisions')]
    public function status_decision_uses_cumulative_reservation_and_fails_closed(
        string $mode,
        string $reserved,
        string $amount,
        ?string $limit,
        RefundStatus $expected,
    ): void {
        $this->assertSame($expected, RefundDecision::status($mode, $reserved, $amount, $limit));
    }

    public static function decisions(): array
    {
        return [
            'small auto' => ['auto', '0.00', '20.00', '50.00', RefundStatus::PENDING],
            'exact ceiling' => ['auto', '30.00', '20.00', '50.00', RefundStatus::PENDING],
            'above ceiling' => ['auto', '0.00', '80.00', '50.00', RefundStatus::PENDING_APPROVAL],
            'split auto' => ['auto', '30.00', '30.00', '50.00', RefundStatus::PENDING_APPROVAL],
            'explicit approval' => ['needs_approval', '0.00', '20.00', '50.00', RefundStatus::PENDING_APPROVAL],
            'null limit' => ['auto', '0.00', '20.00', null, RefundStatus::PENDING_APPROVAL],
            'empty limit' => ['auto', '0.00', '20.00', '', RefundStatus::PENDING_APPROVAL],
            'invalid limit' => ['auto', '0.00', '20.00', 'invalid', RefundStatus::PENDING_APPROVAL],
            'negative limit' => ['auto', '0.00', '20.00', '-1', RefundStatus::PENDING_APPROVAL],
            'precision limit' => ['auto', '0.00', '20.00', '50.001', RefundStatus::PENDING_APPROVAL],
            'newline limit' => ['auto', '0.00', '20.00', "50.00\n", RefundStatus::PENDING_APPROVAL],
        ];
    }
}
