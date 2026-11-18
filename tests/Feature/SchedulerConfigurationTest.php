<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class SchedulerConfigurationTest extends TestCase
{
    #[Test]
    public function default_compose_runs_exactly_one_dedicated_scheduler_process(): void
    {
        $compose = Yaml::parseFile(base_path('docker-compose.yml'));
        $services = $compose['services'] ?? [];

        $this->assertArrayHasKey('scheduler', $services);
        $this->assertSame(
            ['php', '/var/www/artisan', 'schedule:work'],
            $services['scheduler']['command']
        );
        $this->assertSame('unless-stopped', $services['scheduler']['restart']);
        $this->assertArrayNotHasKey('deploy', $services['scheduler']);
        $this->assertSame('dops-scheduler', $services['scheduler']['container_name']);
        $this->assertSame($services['app']['build'], $services['scheduler']['build']);
        $this->assertSame($services['app']['user'], $services['scheduler']['user']);
        $this->assertSame($services['app']['volumes'], $services['scheduler']['volumes']);
        $this->assertSame($services['app']['depends_on'], $services['scheduler']['depends_on']);
        $this->assertSame($services['app']['networks'], $services['scheduler']['networks']);

        foreach (['APP_ENV=local', 'DB_HOST=mysql', 'REDIS_HOST=redis'] as $environmentEntry) {
            $this->assertContains($environmentEntry, $services['scheduler']['environment']);
        }
        $this->assertContains('LOG_CHANNEL=stderr', $services['scheduler']['environment']);

        $schedulerServices = array_filter(
            $services,
            static fn (array $service): bool => ($service['command'] ?? null)
                === ['php', '/var/www/artisan', 'schedule:work']
        );

        $this->assertCount(1, $schedulerServices);
        $this->assertSame(
            'supervisord -c /etc/supervisor/conf.d/worker.conf',
            $services['worker']['command']
        );
    }

    #[Test]
    public function recovery_schedule_is_minutely_non_overlapping_and_visible_on_stdout(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains(
                $event->command,
                'orders:recover-expired --limit=100'
            )
        ));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(5, $events[0]->expiresAt);
        $this->assertSame('/proc/1/fd/1', $events[0]->output);
    }
}
