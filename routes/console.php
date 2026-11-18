<?php

declare(strict_types=1);

use App\Application\UseCases\ExecuteRefund\ExecuteRefundUseCase;
use App\Application\UseCases\ProcessOrder\ApproveLegacyProcessingRetryUseCase;
use App\Application\UseCases\ProcessOrder\RecoverExpiredProcessingOrdersUseCase;
use App\Domain\Order\Enums\ProcessingRecoveryState;
use App\Domain\Refund\RefundStatus;
use App\Models\Refund;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:recover-expired {--limit=100}', function () {
    $count = app(RecoverExpiredProcessingOrdersUseCase::class)
        ->execute((int) $this->option('limit'));

    $this->info("Requeued {$count} recoverable processing order(s).");

    return Command::SUCCESS;
})->purpose('Requeue expired leases and operator-approved legacy work');

Artisan::command('orders:legacy-processing {--limit=100}', function () {
    $rows = DB::table('orders')
        ->where(
            'processing_recovery_state',
            ProcessingRecoveryState::RECONCILIATION_REQUIRED->value
        )
        ->orderBy('id')
        ->limit(max(1, min((int) $this->option('limit'), 1000)))
        ->get([
            'id',
            'status',
            'processing_attempt',
            'processing_claimed_at',
            'processing_lease_until',
            'payment_idempotency_key',
        ]);

    $this->table([
        'Order',
        'Status',
        'Attempt',
        'Claimed at',
        'Lease until',
        'Payment key',
    ], $rows->map(fn ($row) => (array) $row)->all());

    return Command::SUCCESS;
})->purpose('List PROCESSING orders that require explicit reconciliation');

Artisan::command(
    'orders:allow-legacy-retry {orderId} {--confirmed-no-payment}',
    function () {
        if (! $this->option('confirmed-no-payment')) {
            $this->error(
                'Refusing retry: confirm authoritative evidence that payment never started.'
            );

            return Command::FAILURE;
        }

        $approved = app(ApproveLegacyProcessingRetryUseCase::class)
            ->execute((int) $this->argument('orderId'));

        if (! $approved) {
            $this->error(
                'Order is not a canonical reconciliation-required legacy PROCESSING row.'
            );

            return Command::FAILURE;
        }

        $this->info('Legacy PROCESSING order marked SAFE_TO_RECOVER.');

        return Command::SUCCESS;
    }
)->purpose('Allow retry only after confirming the historical payment never started');

Artisan::command('refunds:reconcile {refundId}', function () {
    $refund = Refund::query()->find((int) $this->argument('refundId'));
    if ($refund === null) {
        $this->error('Refund not found.');

        return Command::FAILURE;
    }

    if ($refund->status === RefundStatus::PENDING) {
        $refund = app(ExecuteRefundUseCase::class)->execute($refund->id);
    }

    $this->info($refund->status->value);

    return Command::SUCCESS;
})->purpose('Reconcile a pending refund using its stored provider idempotency key');

Schedule::command('orders:recover-expired --limit=100')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->sendOutputTo('/proc/1/fd/1');
