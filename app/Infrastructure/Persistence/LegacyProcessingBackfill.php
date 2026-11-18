<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ProcessingRecoveryState;
use Illuminate\Support\Facades\DB;

final class LegacyProcessingBackfill
{
    /**
     * Classify PROCESSING rows without inventing historical lease or payment data.
     *
     * @return array{leased: int, reconciliation_required: int}
     */
    public function run(): array
    {
        return DB::transaction(function (): array {
            $leased = DB::table('orders')
                ->where('status', OrderStatus::PROCESSING->value)
                ->whereNull('processing_recovery_state')
                ->whereNotNull('processing_token')
                ->whereNotNull('processing_claimed_at')
                ->whereNotNull('processing_lease_until')
                ->where('processing_attempt', '>', 0)
                ->whereNotNull('payment_idempotency_key')
                ->update([
                    'processing_recovery_state' => ProcessingRecoveryState::LEASED->value,
                ]);

            $reconciliationRequired = DB::table('orders')
                ->where('status', OrderStatus::PROCESSING->value)
                ->whereNull('processing_recovery_state')
                ->update([
                    'processing_recovery_state' => ProcessingRecoveryState::RECONCILIATION_REQUIRED->value,
                ]);

            return [
                'leased' => $leased,
                'reconciliation_required' => $reconciliationRequired,
            ];
        });
    }
}
