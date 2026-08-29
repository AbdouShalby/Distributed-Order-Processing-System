<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue\Jobs;

use App\Application\UseCases\ProcessOrder\ProcessOrderUseCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Exponential backoff in seconds.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [1, 3, 5];
    }

    public function __construct(
        public readonly int $orderId,
        public readonly string $traceId,
    ) {}

    public function handle(ProcessOrderUseCase $useCase): void
    {
        Log::info('ProcessOrderJob started', [
            'order_id' => $this->orderId,
            'trace_id' => $this->traceId,
            'attempt' => $this->attempts(),
        ]);

        $outcome = $useCase->execute($this->orderId, $this->traceId);

        Log::info('ProcessOrderJob completed', [
            'order_id' => $this->orderId,
            'trace_id' => $this->traceId,
            'outcome' => $outcome->value,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessOrderJob failed permanently', [
            'order_id' => $this->orderId,
            'trace_id' => $this->traceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
