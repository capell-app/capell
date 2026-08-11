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
    $registry->register(healthTestCheck('unsafe', run: static fn (): never => throw new RuntimeException('Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature api_key=sk-live-123 mysql://admin:hunter2@db.internal/capell customer-4471')));

    $result = new RunHealthCheckAction($registry, new HealthSummarySanitizer)->handle('unsafe');

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->summary)->toBe('Check raised RuntimeException.')
        ->and($result->summary)->not->toContain('Bearer', 'sk-live', 'hunter2', 'customer-4471');
});

it('redacts sensitive patterns in contributor summaries', function (): void {
    $summary = new HealthSummarySanitizer()->sanitize('Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature api_key=sk-live-123 mysql://admin:hunter2@db.internal/capell user@example.com /private/customer/file.txt');

    expect($summary)->toContain('Bearer [redacted]', 'api_key=[redacted]', 'mysql:/[path]', '[email]')
        ->and($summary)->not->toContain('eyJhbGci', 'sk-live', 'hunter2', 'admin', 'db.internal', 'user@example.com', '/private/customer');
});

it('rejects sensitive or structured machine metrics', function (): void {
    expect(fn (): HealthCheckResultData => new HealthCheckResultData(
        'metric',
        'runtime',
        HealthStatus::Healthy,
        HealthSeverity::Info,
        'Healthy.',
        metrics: ['detail' => 'secret=abc'],
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): HealthCheckResultData => HealthCheckResultData::fromArray([
            'id' => 'metric',
            'category' => 'runtime',
            'status' => 'healthy',
            'severity' => 'info',
            'summary' => 'Healthy.',
            'metrics' => ['nested' => ['secret' => 'abc']],
        ]))->toThrow(InvalidArgumentException::class);

    expect(fn (): HealthCheckResultData => new HealthCheckResultData(
        'metric',
        'runtime',
        HealthStatus::Healthy,
        HealthSeverity::Info,
        'Healthy.',
        metrics: ['customer@example.com' => 1],
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): HealthCheckResultData => new HealthCheckResultData(
            'metric',
            'runtime',
            HealthStatus::Healthy,
            HealthSeverity::Info,
            'Healthy.',
            metrics: ['ratio' => INF],
        ))->toThrow(InvalidArgumentException::class);
});

it('accepts finite scalar machine telemetry', function (): void {
    $result = new HealthCheckResultData(
        'metric',
        'runtime',
        HealthStatus::Healthy,
        HealthSeverity::Info,
        'Healthy.',
        metrics: ['count' => 2, 'ratio' => 0.5, 'enabled' => true, 'optional' => null],
    );

    expect($result->metrics)->toBe(['count' => 2, 'ratio' => 0.5, 'enabled' => true, 'optional' => null]);
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

it('fails closed when the health subprocess exposes sensitive exception detail', function (): void {
    $registry = new HealthCheckRegistry($this->app);
    $registry->register(healthTestCheck('unsafe-process'));
    $registry->register(healthTestCheck('z.safe-process'));

    $payload = json_encode(new HealthCheckResultData('z.safe-process', 'runtime', HealthStatus::Healthy, HealthSeverity::Info, 'Healthy.')->toArray(), JSON_THROW_ON_ERROR);

    $factory = new class($payload) implements ProcessFactoryInterface
    {
        private int $calls = 0;

        public function __construct(private readonly string $payload) {}

        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            throw_if($this->calls++ === 0, RuntimeException::class, 'Bearer secret-token api_key=sk-live-123 customer-4471');

            return new Process([PHP_BINARY, '-r', 'echo $argv[1];', $this->payload]);
        }
    };

    $results = new BuildHealthReportAction($registry, $factory, new HealthSummarySanitizer)->handle()->checks;

    expect($results)->toHaveCount(2)
        ->and($results[0]->status)->toBe(HealthStatus::Error)
        ->and($results[0]->summary)->toBe('Check execution failed (RuntimeException).')
        ->and($results[0]->summary)->not->toContain('Bearer', 'secret-token', 'sk-live', 'customer-4471')
        ->and($results[1]->status)->toBe(HealthStatus::Healthy);
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
