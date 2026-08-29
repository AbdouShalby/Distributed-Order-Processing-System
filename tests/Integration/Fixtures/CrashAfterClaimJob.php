<?php

declare(strict_types=1);

namespace Tests\Integration\Fixtures;

use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CrashAfterClaimJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $orderId,
        public readonly string $traceId,
    ) {}

    public function handle(OrderRepositoryInterface $orders): void
    {
        if ($this->attempts() > 1) {
            ProcessOrderJob::dispatch($this->orderId, $this->traceId)
                ->afterCommit();

            return;
        }

        $claimedAt = new \DateTimeImmutable;
        $claim = $orders->claimForProcessing(
            $this->orderId,
            $claimedAt,
            $claimedAt->modify('+2 seconds'),
            'integration-crashed-queue-worker'
        );

        if ($claim === null) {
            throw new \RuntimeException('Crash fixture could not claim the order.');
        }

        fwrite(STDERR, "Intentional integration worker crash after claim.\n");
        exit(86);
    }
}
