<?php

declare(strict_types=1);

use Capell\Core\Actions\Reporting\DispatchSignalAction;
use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\DispatchResultData;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Tests\Support\ReportingLogRecorder;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;

beforeEach(function (): void {
    $this->initialReportingConfiguration = config('capell-reporting');
    $this->reportingRecords = new ReportingLogRecorder(storage_path('framework/testing/reporting-dispatch-' . bin2hex(random_bytes(8)) . '.log'));
    $logs = new LogManager($this->app);

    config()->set('logging.default', 'reporting-test');
    config()->set('logging.channels.reporting-test', ['driver' => 'single', 'path' => $this->reportingRecords->path]);

    $this->app->instance('log', $logs);
    $this->app->instance(LogManager::class, $logs);

    $this->reportingLogs = $logs;
    config()->set('capell-reporting', require __DIR__ . '/../../../config/capell-reporting.php');
    config()->set('capell-reporting.cache_store', 'array');
});

afterEach(function (): void {
    $this->reportingRecords->clear();
});

function reportingTestSignal(string $correlationId = 'trace-1', Severity $severity = Severity::Error, string $runId = 'run-1'): SignalData
{
    return new SignalData('import.failed', FailureCategory::Dependency, $severity, 'Import failed.', 'Check the source.', $correlationId, $runId, ['password' => 'never-log-this']);
}

it('registers enabled log defaults without requiring published configuration', function (): void {
    expect($this->initialReportingConfiguration)->toBeArray()
        ->and($this->initialReportingConfiguration['enabled'])->toBeTrue()
        ->and($this->initialReportingConfiguration['defaults']['transport'])->toBe('log')
        ->and($this->initialReportingConfiguration['defaults']['cooldown_seconds'])->toBe(300);
});

it('emits structured JSON to the default log channel', function (): void {
    $signal = reportingTestSignal();
    $result = resolve(DispatchSignalAction::class)->handle($signal);
    $records = $this->reportingRecords->getRecords();

    expect($result->status)->toBe(DispatchStatus::Reported)
        ->and($result->transport)->toBe('log')
        ->and($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe('ERROR')
        ->and(json_decode((string) $records[0]->message, true, flags: JSON_THROW_ON_ERROR))->toBe(RedactedSignalData::fromSignal($signal)->toArray())
        ->and($records[0]->message)->not->toContain('never-log-this');
});

it('suppresses duplicates until the exact cooldown boundary across dispatcher instances', function (): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 60);
    $this->freezeTime();

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported);
    $this->travel(59)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Suppressed);
    $this->travel(1)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toHaveCount(2);
});

it('keeps correlations runs and increased severity independent', function (): void {
    $dispatcher = resolve(DispatchSignalAction::class);
    foreach ([reportingTestSignal(), reportingTestSignal('trace-2'), reportingTestSignal(severity: Severity::Critical), reportingTestSignal(runId: 'run-2')] as $signal) {
        expect($dispatcher->handle($signal)->status)->toBe(DispatchStatus::Reported);
    }

    expect($this->reportingRecords->getRecords())->toHaveCount(4);
});

it('does not let changing diagnostic text evade cooldown', function (): void {
    $dispatcher = resolve(DispatchSignalAction::class);
    $dispatcher->handle(reportingTestSignal());

    $changed = new SignalData('import.failed', FailureCategory::Dependency, Severity::Error, 'A second detail.', 'Inspect another source.', 'trace-1', 'run-1', ['attempt' => 2]);

    expect($dispatcher->handle($changed)->status)->toBe(DispatchStatus::Suppressed);
});

it('disables deduplication when the cooldown is zero', function (): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    $dispatcher = resolve(DispatchSignalAction::class);

    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toHaveCount(2);
});

it('applies defaults then category then exact signal policy with field inheritance', function (): void {
    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->twice()->withArgs(fn (RedactedSignalData $signal): bool => $signal->context['password'] === '[redacted]');
    $this->app->instance('reporting.test', $reporter);
    config()->set('capell-reporting.reporters.test', 'reporting.test');
    config()->set('capell-reporting.defaults.cooldown_seconds', 300);
    config()->set('capell-reporting.categories', ['dependency' => ['transport' => 'test', 'cooldown_seconds' => 60, 'enabled' => false]]);
    config()->set('capell-reporting.signals', ['import.failed' => ['enabled' => true, 'cooldown_seconds' => 10]]);
    $this->freezeTime();
    $dispatcher = resolve(DispatchSignalAction::class);

    expect($dispatcher->handle(reportingTestSignal())->transport)->toBe('test')
        ->and($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Suppressed);
    $this->travel(10)->seconds();
    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toBe([]);
});

it('allows exact signal transport overrides and unknown signal names inherit defaults', function (): void {
    config()->set('capell-reporting.categories', ['dependency' => ['transport' => 'missing']]);
    config()->set('capell-reporting.signals', ['import.failed' => ['transport' => 'log']]);

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported);

    $unknown = new SignalData('new.failure', FailureCategory::Runtime, Severity::Warning, 'Failed.', 'Inspect.', 'trace-1');
    expect(resolve(DispatchSignalAction::class)->handle($unknown)->status)->toBe(DispatchStatus::Reported);
});

it('honours the global off switch even when a signal opts in and configuration is otherwise invalid', function (): void {
    config()->set('capell-reporting.enabled', false);
    config()->set('capell-reporting.defaults', 'broken');
    config()->set('capell-reporting.signals', ['import.failed' => ['enabled' => true]]);

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Disabled)
        ->and($this->reportingRecords->getRecords())->toBe([]);
});

it('disables configured categories or signals without claiming cooldown', function (string $section, string $key): void {
    config()->set('capell-reporting.' . $section, [$key => ['enabled' => false]]);
    $dispatcher = resolve(DispatchSignalAction::class);
    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Disabled)
        ->and($this->reportingRecords->getRecords())->toBe([]);
    config()->set('capell-reporting.' . $section, []);
    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported);
})->with([['categories', 'dependency'], ['signals', 'import.failed']]);

it('falls back to logs for missing unknown or invalid transports', function (mixed $binding): void {
    config()->set('capell-reporting.defaults.transport', 'external');
    config()->set('capell-reporting.reporters', ['external' => $binding]);

    $result = resolve(DispatchSignalAction::class)->handle(reportingTestSignal());
    expect($result->status)->toBe(DispatchStatus::Fallback)
        ->and($result->transport)->toBe('log')
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
})->with([null, 'MissingReporter', stdClass::class, 42]);

it('falls back to logs for missing or malformed configuration', function (mixed $configuration): void {
    config()->set('capell-reporting', $configuration);

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
})->with([[null], ['broken'], [['enabled' => 'false']], [['defaults' => ['cooldown_seconds' => -1]]]]);

it('falls back when a transport throws without exposing its exception', function (): void {
    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->once()->andThrow(new RuntimeException('password=transport-secret person@example.com'));
    $this->app->instance('reporting.broken', $reporter);
    config()->set('capell-reporting.defaults.transport', 'broken');
    config()->set('capell-reporting.reporters.broken', 'reporting.broken');

    $dispatcher = resolve(DispatchSignalAction::class);

    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Suppressed)
        ->and($this->reportingRecords->getRecords()[0]->message)->not->toContain('transport-secret')
        ->and($this->reportingRecords->getRecords()[0]->message)->not->toContain('person@example.com');
});

it('falls back when the cache is unavailable', function (): void {
    $cache = Mockery::mock(Factory::class);
    $cache->shouldReceive('store')->andThrow(new RuntimeException('cache secret'));
    $this->app->instance(Factory::class, $cache);

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('uses the default logger if the selected log channel fails', function (): void {
    config()->set('logging.channels.missing', ['driver' => 'broken']);
    config()->set('capell-reporting.log_channel', 'missing');

    $this->reportingLogs->extend('broken', fn (): never => throw new RuntimeException('missing channel'));

    expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('uses a selected valid log channel and bypasses an unknown channel before logger resolution', function (): void {
    $records = new ReportingLogRecorder(storage_path('framework/testing/reporting-operations-' . bin2hex(random_bytes(8)) . '.log'));

    try {
        config()->set('logging.channels.operations', ['driver' => 'single', 'path' => $records->path]);
        config()->set('capell-reporting.log_channel', 'operations');

        expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
            ->and($records->getRecords())->toHaveCount(1)
            ->and($this->reportingRecords->getRecords())->toBe([]);
        config()->set('capell-reporting.log_channel', 'unknown');
        expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal('trace-2'))->status)->toBe(DispatchStatus::Fallback)
            ->and($this->reportingRecords->getRecords())->toHaveCount(1);
    } finally {
        $records->clear();
    }
});

it('fails closed when configuration resolution itself throws', function (): void {
    $container = new Container;
    $container->bind(Repository::class, static fn (): never => throw new RuntimeException('configuration secret'));
    $container->instance(LogManager::class, $this->reportingLogs);

    $result = new DispatchSignalAction($container)->handle(reportingTestSignal());

    expect($result->status)->toBe(DispatchStatus::Failed)
        ->and($result->reason)->toBe('log_unavailable')
        ->and($this->reportingRecords->getRecords())->toBe([]);
});

it('never throws when logging also fails and releases the failed delivery claim', function (): void {
    $this->reportingLogs->extend('broken', fn (): never => throw new RuntimeException('log failure'));
    config()->set('logging.channels.broken', ['driver' => 'broken']);
    config()->set('logging.default', 'broken');

    $dispatcher = resolve(DispatchSignalAction::class);

    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Failed);
    config()->set('logging.default', 'reporting-test');
    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('preserves a replacement claim when an older delivery fails after cooldown', function (): void {
    $this->freezeTime();
    config()->set('capell-reporting.defaults.cooldown_seconds', 5);
    config()->set('capell-reporting.defaults.transport', 'slow');
    config()->set('capell-reporting.reporters.slow', 'reporting.slow');

    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->once()->andReturnUsing(static function (): never {
        Fiber::suspend();
        throw new RuntimeException('expired delivery');
    });
    $this->app->instance('reporting.slow', $reporter);
    $dispatcher = resolve(DispatchSignalAction::class);

    $delivery = new Fiber(fn (): DispatchResultData => $dispatcher->handle(reportingTestSignal()));
    $delivery->start();
    $this->travel(5)->seconds();
    config()->set('capell-reporting.defaults.transport', 'log');

    try {
        expect(resolve(DispatchSignalAction::class)->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Reported);
    } finally {
        $this->reportingLogs->extend('broken', fn (): never => throw new RuntimeException('unavailable'));
        config()->set('logging.channels.broken', ['driver' => 'broken']);
        config()->set('logging.default', 'broken');
        $delivery->resume();
    }

    expect($delivery->getReturn()->status)->toBe(DispatchStatus::Failed);
    config()->set('logging.default', 'reporting-test');
    expect($dispatcher->handle(reportingTestSignal())->status)->toBe(DispatchStatus::Suppressed);
});
