<?php

declare(strict_types=1);

use Capell\Core\Actions\Reporting\DispatchSignalAction;
use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\DispatchResultData;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Support\Reporting\SignalDispatchGuard;
use Capell\Core\Tests\Support\ReportingLogRecorder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Laravel\Octane\Listeners\FlushArrayCache;

beforeEach(function (): void {
    $this->reportingRecords = new ReportingLogRecorder(storage_path('framework/testing/reporting-failure-' . bin2hex(random_bytes(8)) . '.log'));
    config()->set('logging.default', 'reporting-test');
    config()->set('logging.channels.reporting-test', ['driver' => 'single', 'path' => $this->reportingRecords->path]);
    config()->set('capell-reporting', require __DIR__ . '/../../../config/capell-reporting.php');
    config()->set('capell-reporting.cache_store', 'array');
});

afterEach(function (): void {
    $this->reportingRecords->clear();
});

function failureSafetySignal(string $correlationId = 'trace-1'): SignalData
{
    return new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Failed.', 'Inspect.', $correlationId);
}

it('suppresses transport re-entry across fresh dispatchers and resets after delivery', function (): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    config()->set('capell-reporting.defaults.transport', 'recursive');
    config()->set('capell-reporting.reporters.recursive', 'reporting.recursive');

    $nested = [];
    $calls = 0;
    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->andReturnUsing(function () use (&$nested, &$calls): void {
        if (++$calls < 4) {
            $nested[] = new DispatchSignalAction($this->app)->handle(failureSafetySignal('nested-' . $calls));
        }
    });
    $this->app->instance('reporting.recursive', $reporter);

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Reported)
        ->and($calls)->toBe(1)
        ->and($nested)->toHaveCount(1)
        ->and($nested[0]->status)->toBe(DispatchStatus::Suppressed)
        ->and($nested[0]->reason)->toBe('recursive_dispatch');

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Reported)
        ->and($calls)->toBe(2);
});

it('does not expose redacted delivery to host log listeners during fallback', function (): void {
    config()->set('capell-reporting');
    $calls = 0;
    resolve(Dispatcher::class)->listen(MessageLogged::class, function () use (&$calls): void {
        $calls++;
    });

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($calls)->toBe(0)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('isolates active deliveries between execution fibres and application containers', function (bool $separateContainer): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    config()->set('capell-reporting.defaults.transport', 'suspended');
    config()->set('capell-reporting.reporters.suspended', 'reporting.suspended');

    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->once()->andReturnUsing(static fn (): mixed => Fiber::suspend());
    $this->app->instance('reporting.suspended', $reporter);
    $fiber = new Fiber(fn (): DispatchResultData => new DispatchSignalAction($this->app)->handle(failureSafetySignal()));
    $fiber->start();

    config()->set('capell-reporting.defaults.transport', 'log');
    $container = $separateContainer ? clone app() : app();

    try {
        expect(new DispatchSignalAction($container)->handle(failureSafetySignal('trace-2'))->status)->toBe(DispatchStatus::Reported);
    } finally {
        $fiber->resume();
    }

    expect($fiber->getReturn()->status)->toBe(DispatchStatus::Reported);
})->with([false, true]);

it('allows another application to report within the same execution fibre', function (): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    config()->set('capell-reporting.defaults.transport', 'other-application');
    config()->set('capell-reporting.reporters.other-application', 'reporting.other-application');

    $reporter = Mockery::mock(Reporter::class);
    $reporter->shouldReceive('report')->once()->andReturnUsing(function (): void {
        $container = clone app();
        config()->set('capell-reporting.defaults.transport', 'log');

        expect(new DispatchSignalAction($container)->handle(failureSafetySignal('trace-2'))->status)->toBe(DispatchStatus::Reported);
    });
    $this->app->instance('reporting.other-application', $reporter);

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('does not retain an abandoned application through dispatch guard state', function (): void {
    $container = new Container;
    $reference = WeakReference::create($container);
    expect(SignalDispatchGuard::enter($container))->toBeTrue();

    unset($container);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('fails safely when the default logger is callback configured and allows recovery', function (): void {
    config()->set('logging.channels.broken', ['driver' => 'custom', 'via' => static fn (): never => throw new RuntimeException('password=LOGGER_CONSTRUCTION_SECRET')]);
    config()->set('logging.default', 'broken');

    $result = resolve(DispatchSignalAction::class)->handle(failureSafetySignal());
    expect($result->status)->toBe(DispatchStatus::Failed)
        ->and($result->reason)->toBe('log_unavailable');

    config()->set('logging.default', 'reporting-test');
    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Reported)
        ->and($this->reportingRecords->getRecords())->toHaveCount(1);
});

it('prunes expired reporting array claims across actual Octane cache flushes', function (): void {
    $this->freezeTime();
    config()->set('capell-reporting.defaults.cooldown_seconds', 1);
    $store = resolve(Factory::class)->store('array')->getStore();
    $this->assertInstanceOf(ArrayStore::class, $store);
    $foreign = $store->lock('other-feature:expired', 1);
    $foreign->get();

    $live = $store->lock('other-feature:live', 300);
    $live->get();

    for ($batch = 0; $batch < 3; $batch++) {
        for ($index = 0; $index < 20; $index++) {
            expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal('batch-' . $batch . '-' . $index))->status)->toBe(DispatchStatus::Reported);
        }

        expect($store->locks)->toHaveCount(22)
            ->and($store->locks['other-feature:expired']['owner'])->toBe($foreign->owner())
            ->and($store->locks['other-feature:live']['owner'])->toBe($live->owner());
        $this->travel(1)->seconds();
        new FlushArrayCache()->handle((object) ['sandbox' => $this->app]);
    }
});

it('bounds live reporting array claims without evicting active owners', function (): void {
    $this->freezeTime();
    $store = resolve(Factory::class)->store('array')->getStore();
    $this->assertInstanceOf(ArrayStore::class, $store);
    $signal = failureSafetySignal();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported);
    for ($index = 1; $index < 1000; $index++) {
        $store->lock('capell:reporting:' . failureSafetySignal('existing-' . $index)->fingerprint(), 60)->get();
    }

    $claims = $store->locks;

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed);
    $result = resolve(DispatchSignalAction::class)->handle(failureSafetySignal('over-capacity'));
    expect($result->status)->toBe(DispatchStatus::Fallback)
        ->and($result->reason)->toBe('deduplication_unavailable')
        ->and($store->locks)->toBe($claims);
});
