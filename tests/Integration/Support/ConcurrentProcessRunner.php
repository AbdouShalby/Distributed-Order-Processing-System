<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class ConcurrentProcessRunner
{
    /**
     * @param  array<int, array{action: string, payload?: array<string, mixed>, env?: array<string, string>, allowed_exit_codes?: int[]}>  $actors
     * @return array<int, array<string, mixed>>
     */
    public function run(array $actors): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException(
                'The real concurrency suite must run through docker-compose.integration.yml.'
            );
        }

        $barrier = 'integration:barrier:'.Str::uuid()->toString();
        Redis::del("{$barrier}:ready", "{$barrier}:start");
        $processes = [];

        try {
            foreach ($actors as $index => $actor) {
                $encodedPayload = base64_encode(json_encode(
                    $actor['payload'] ?? [],
                    JSON_THROW_ON_ERROR
                ));
                $command = [
                    PHP_BINARY,
                    base_path('tests/Integration/Fixtures/concurrent_actor.php'),
                    $actor['action'],
                    $barrier,
                    (string) $index,
                    $encodedPayload,
                ];
                $environment = array_merge(
                    getenv(),
                    $actor['env'] ?? []
                );
                $pipes = [];
                $process = proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    base_path(),
                    $environment
                );

                if (! is_resource($process)) {
                    throw new \RuntimeException("Unable to start concurrent actor {$index}.");
                }

                fclose($pipes[0]);
                $processes[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'allowed_exit_codes' => $actor['allowed_exit_codes'] ?? [],
                ];
            }

            $deadline = microtime(true) + 20;
            while ((int) Redis::get("{$barrier}:ready") !== count($actors)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(
                        'Concurrent actors did not reach the Redis synchronization barrier.'
                    );
                }
                usleep(10_000);
            }

            Redis::set("{$barrier}:start", 'go', 'EX', 30);

            $results = [];
            foreach ($processes as $index => $entry) {
                $stdout = stream_get_contents($entry['pipes'][1]);
                $stderr = stream_get_contents($entry['pipes'][2]);
                fclose($entry['pipes'][1]);
                fclose($entry['pipes'][2]);
                $exitCode = proc_close($entry['process']);

                if ($exitCode !== 0) {
                    if (in_array($exitCode, $entry['allowed_exit_codes'], true)) {
                        $results[$index] = [
                            'exit_code' => $exitCode,
                            'stderr' => trim($stderr),
                        ];

                        continue;
                    }

                    throw new \RuntimeException(
                        "Concurrent actor {$index} exited {$exitCode}: {$stderr}\n{$stdout}"
                    );
                }

                try {
                    $results[$index] = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new \RuntimeException(
                        "Concurrent actor {$index} returned invalid JSON: {$stdout}\n{$stderr}",
                        previous: $exception
                    );
                }
            }

            return $results;
        } finally {
            $this->terminate($processes);
            Redis::del(
                "{$barrier}:ready",
                "{$barrier}:start",
                "{$barrier}:lock-held",
                "{$barrier}:lock-release",
                "{$barrier}:legacy-ready",
                "{$barrier}:crash-target-pid",
                "{$barrier}:provider-persisted",
                "{$barrier}:provider-release"
            );
        }
    }

    private function terminate(array $processes): void
    {
        foreach ($processes as $entry) {
            foreach ($entry['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($entry['process'])) {
                $status = proc_get_status($entry['process']);
                if ($status['running']) {
                    proc_terminate($entry['process']);
                }
                proc_close($entry['process']);
            }
        }
    }
}
