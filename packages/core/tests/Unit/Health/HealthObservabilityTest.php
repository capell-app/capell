<?php

declare(strict_types=1);

use Capell\Core\Actions\Health\BuildHealthReportAction;
use Capell\Core\Actions\Health\RunHealthCheckAction;
use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Capell\Core\Support\Health\DiskCapacityHealthCheck;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\Health\HealthSummarySanitizer;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final readonly class HealthTestCheck implements HealthCheck
{
    /**
     * @param  non-empty-string  $checkId
     * @param  non-empty-string  $checkCategory
     * @param  positive-int  $timeout
     */
    public function __construct(private string $checkId, private string $checkCategory = 'runtime', private int $timeout = 2, private ?Closure $callback = null) {}

    public function id(): string
    {
        return $this->checkId;
    }

    public function category(): string
    {
        return $this->checkCategory;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout;
    }

    public function run(): HealthCheckResultData
    {
        return $this->callback instanceof Closure
            ? ($this->callback)()
            : new HealthCheckResultData($this->checkId, $this->checkCategory, HealthStatus::Healthy, HealthSeverity::Info, 'Healthy.');
    }
}

/**
 * @param  non-empty-string  $id
 * @param  non-empty-string  $category
 * @param  positive-int  $timeout
 */
function healthTestCheck(string $id, string $category = 'runtime', int $timeout = 2, ?Closure $run = null): HealthCheck
{
    return new HealthTestCheck($id, $category, $timeout, $run);
}

it('discovers tagged checks and returns them in deterministic identity order', function (): void {
    $this->app->instance('health.z', healthTestCheck('z.check'));
    $this->app->instance('health.a', healthTestCheck('a.check'));
    $this->app->tag(['health.z', 'health.a'], HealthCheck::TAG);

    $registry = new HealthCheckRegistry($this->app);

    expect(array_map(static fn (HealthCheck $check): string => $check->id(), $registry->checks()))->toBe(['a.check', 'z.check']);
});

it('rejects empty and duplicate check identities', function (): void {
    $registry = new HealthCheckRegistry($this->app);
    $invalid = Mockery::mock(HealthCheck::class);
    $invalid->shouldReceive('id')->andReturn('');
    $invalid->shouldReceive('category')->andReturn('runtime');
    $invalid->shouldReceive('timeoutSeconds')->andReturn(1);

    expect(fn (): HealthCheckRegistry => $registry->register($invalid))->toThrow(InvalidArgumentException::class)
        ->and(fn (): HealthCheckRegistry => $registry->register(healthTestCheck('same'))->register(healthTestCheck('same')))->toThrow(InvalidArgumentException::class);
});

it('records healthy and failing disk capacity with human and machine values', function (): void {
    $free = 2 * 1024 * 1024;
    $probe = static fn (string $path): int => $free;
    $healthy = new DiskCapacityHealthCheck(sys_get_temp_dir(), $free - 1, probe: $probe)->run();
    $failed = new DiskCapacityHealthCheck(sys_get_temp_dir(), $free + 1024, probe: $probe)->run();

    expect($healthy->status)->toBe(HealthStatus::Healthy)
        ->and($healthy->summary)->toContain('free; minimum')
        ->and($failed->status)->toBe(HealthStatus::Failed)
        ->and($failed->summary)->toContain('shortfall 1.0 KiB')
        ->and($failed->metrics['shortfallBytes'])->toBe(1024);
});

it('sanitizes check output and exception detail', function (): void {
    $registry = new HealthCheckRegistry($this->app);
    $registry->register(healthTestCheck('unsafe', run: static fn (): never => throw new RuntimeException('token=abc user@example.com in /private/customer/file.txt')));

    $result = new RunHealthCheckAction($registry, new HealthSummarySanitizer)->handle('unsafe');

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->summary)->toContain('token=[redacted]', '[email]', '[path]')
        ->and($result->summary)->not->toContain('abc', 'user@example.com', '/private/customer');
});

it('sanitizes string metrics in successful results', function (): void {
    $registry = new HealthCheckRegistry($this->app);
    $registry->register(healthTestCheck('metric', run: static fn (): HealthCheckResultData => new HealthCheckResultData(
        'metric',
        'runtime',
        HealthStatus::Healthy,
        HealthSeverity::Info,
        'Healthy.',
        metrics: ['detail' => 'secret=abc at /private/customer/file.txt'],
    )));

    $result = new RunHealthCheckAction($registry, new HealthSummarySanitizer)->handle('metric');

    expect($result->metrics['detail'])->toBe('secret=[redacted] at [path]');
});

it('continues after a timed out check and groups results deterministically', function (): void {
    $registry = new HealthCheckRegistry($this->app);
    $registry->register(healthTestCheck('a.slow', 'runtime', 1));
    $registry->register(healthTestCheck('b.fast', 'capacity', 2));

    $payload = json_encode(new HealthCheckResultData('b.fast', 'capacity', HealthStatus::Warning, HealthSeverity::Warning, 'Near threshold.')->toArray(), JSON_THROW_ON_ERROR);
    $factory = new class($payload) implements ProcessFactoryInterface
    {
        private int $calls = 0;

        public function __construct(private readonly string $payload) {}

        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            return new Process($this->calls++ === 0 ? [PHP_BINARY, '-r', 'sleep(2);'] : [PHP_BINARY, '-r', 'echo $argv[1];', $this->payload]);
        }
    };

    $report = new BuildHealthReportAction($registry, $factory, new HealthSummarySanitizer)->handle();

    expect($report->checks)->toHaveCount(2)
        ->and($report->checks[0]->status)->toBe(HealthStatus::TimedOut)
        ->and($report->checks[1]->status)->toBe(HealthStatus::Warning)
        ->and(array_keys($report->grouped()))->toBe(['capacity', 'runtime'])
        ->and($report->status())->toBe(HealthStatus::TimedOut);
});

it('uses non-zero scheduler-safe command semantics', function (): void {
    $payload = json_encode(new HealthCheckResultData('core.disk-capacity', 'capacity', HealthStatus::Warning, HealthSeverity::Warning, 'Warning.')->toArray(), JSON_THROW_ON_ERROR);
    $this->app->instance(ProcessFactoryInterface::class, new readonly class($payload) implements ProcessFactoryInterface
    {
        public function __construct(private string $payload) {}

        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            return new Process([PHP_BINARY, '-r', 'echo $argv[1];', $this->payload]);
        }
    });
    $event = $this->app->make(Schedule::class)->command('capell:health --json');

    expect(Artisan::call('capell:health', ['--json' => true]))->toBe(1)
        ->and($event->command)->toContain('capell:health', '--json');
});
