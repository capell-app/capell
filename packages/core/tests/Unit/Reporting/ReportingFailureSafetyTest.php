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
use Illuminate\Cache\ArrayStore;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Laravel\Octane\Listeners\FlushArrayCache;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

beforeEach(function (): void {
    $this->reportingRecords = new TestHandler;
    $this->emergencyRecords = new TestHandler;
    $logs = new class($this->app, $this->emergencyRecords) extends LogManager
    {
        public function __construct(Application $app, private readonly TestHandler $records)
        {
            parent::__construct($app);
        }

        protected function createEmergencyLogger(): LoggerInterface
        {
            return new IlluminateLogger(new Logger('emergency-test', [$this->records]), $this->app->make(Dispatcher::class));
        }
    };
    $records = $this->reportingRecords;
    $logs->extend('reporting-test', fn (): Logger => new Logger('reporting-test', [$records]));
    $this->app->instance('log', $logs);
    $this->reportingLogs = $logs;
    config()->set('logging.default', 'reporting-test');
    config()->set('logging.channels.reporting-test', ['driver' => 'reporting-test']);
    config()->set('capell-reporting', require __DIR__ . '/../../../config/capell-reporting.php');
    config()->set('capell-reporting.cache_store', 'array');
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

it('suppresses log listener re-entry during configuration fallback', function (): void {
    config()->set('capell-reporting');
    $nested = [];
    $calls = 0;
    resolve(Dispatcher::class)->listen(MessageLogged::class, function () use (&$calls, &$nested): void {
        if (++$calls < 4) {
            $nested[] = new DispatchSignalAction($this->app)->handle(failureSafetySignal());
        }
    });

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($calls)->toBe(1)
        ->and($nested[0]->status)->toBe(DispatchStatus::Suppressed)
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

it('falls back from broken configured loggers without emergency exception output', function (string $failure): void {
    $originalApplication = $this->app;
    $logs = $this->reportingLogs;
    $reportingRecords = $this->reportingRecords;
    $emergencyRecords = $this->emergencyRecords;

    $logs->extend('broken', fn (): never => throw new RuntimeException('password=LOGGER_CONSTRUCTION_SECRET'));
    config()->set('logging.channels.broken', ['driver' => 'broken']);
    if (in_array($failure, ['stack', 'cached stack'], true)) {
        config()->set('logging.channels.selected', ['driver' => 'stack', 'channels' => ['reporting-test', 'broken']]);
    } elseif ($failure === 'delegating driver') {
        $logs->extend('delegating', fn (): LoggerInterface => $this->channel('broken'));
        config()->set('logging.channels.selected', ['driver' => 'delegating']);
    } elseif (in_array($failure, ['factory', 'container tap'], true)) {
        $originalApplication->bind('reporting.delegating-factory', static fn (Application $application): object => new readonly class($application->make(LogManager::class))
        {
            public function __construct(private LogManager $logs) {}

            public function __invoke(): LoggerInterface
            {
                return $this->logs->channel('broken');
            }
        });
        config()->set('logging.channels.selected', $failure === 'factory'
            ? ['driver' => 'custom', 'via' => 'reporting.delegating-factory']
            : ['driver' => 'reporting-test', 'tap' => ['reporting.delegating-factory']]);
    } elseif (str_starts_with($failure, 'container ')) {
        $resolution = match ($failure) {
            'container manager' => LogManager::class,
            'container interface' => LoggerInterface::class,
            default => 'log',
        };
        $logs->extend('delegating', function (Application $application) use ($resolution, $failure): LoggerInterface {
            if ($failure === 'container application') {
                $application = $application->make(Application::class);
            } elseif ($failure === 'container base') {
                $application = $application->make(Container::class);
            }

            return $application->make($resolution)->channel('broken');
        });
        config()->set('logging.channels.selected', ['driver' => 'delegating']);
    } elseif ($failure === 'tap') {
        $originalApplication->bind('reporting.throwing-tap', static fn (): never => throw new RuntimeException('password=LOGGER_CONSTRUCTION_SECRET'));
        config()->set('logging.channels.selected', ['driver' => 'reporting-test', 'tap' => ['reporting.throwing-tap']]);
    } else {
        config()->set('logging.channels.selected', ['driver' => 'broken']);
    }

    config()->set('capell-reporting.log_channel', 'selected');
    if ($failure === 'cached stack') {
        $logs->channel('selected');
        $emergencyRecords->clear();
    }

    if ($failure === 'fallback') {
        config()->set('capell-reporting.defaults.transport', 'unregistered');
    }

    $result = resolve(DispatchSignalAction::class)->handle(failureSafetySignal());

    expect($emergencyRecords->getRecords())->toBe([])
        ->and($result->status)->toBe(DispatchStatus::Fallback)
        ->and($result->reason)->toBe('transport_unavailable')
        ->and($reportingRecords->getRecords())->toHaveCount(1)
        ->and($reportingRecords->getRecords()[0]->message)->not->toContain('LOGGER_CONSTRUCTION_SECRET')
        ->and($originalApplication->make('log'))->toBe($logs)
        ->and($originalApplication->make(Application::class))->toBe($originalApplication);
})->with(['driver', 'tap', 'stack', 'fallback', 'delegating driver', 'cached stack', 'container log', 'container manager', 'container interface', 'container application', 'container base', 'factory', 'container tap']);

it('rebuilds warmed logger callbacks without leaking through the original manager', function (string $callback, string $binding, string $route): void {
    $application = $this->app;
    $logs = $this->reportingLogs;
    $logs->extend('broken', fn (): never => throw new RuntimeException('password=WARM_LOGGER_SECRET'));

    config()->set('logging.channels.broken', ['driver' => 'broken']);
    $application->singleton('reporting.warmed-callback', static fn (Application $app): object => new readonly class($app->make(LogManager::class))
    {
        public function __construct(private LogManager $logs) {}

        public function __invoke(): LoggerInterface
        {
            return $this->logs->channel('broken');
        }
    });
    $warmed = $application->make('reporting.warmed-callback');
    if ($binding === 'instance') {
        unset($application['reporting.warmed-callback']);
        $application->instance('reporting.warmed-callback', $warmed);
    }

    $application->alias('reporting.warmed-callback', 'reporting.callback-alias');
    $name = $binding === 'alias' ? 'reporting.callback-alias' : 'reporting.warmed-callback';
    config()->set('logging.channels.selected', $callback === 'factory'
        ? ['driver' => 'custom', 'via' => $name]
        : ['driver' => 'reporting-test', 'tap' => [$name . ':argument']]);
    config()->set('capell-reporting.log_channel', 'selected');
    if ($route !== 'log') {
        config()->set('capell-reporting.defaults.transport', 'operator');
        config()->set('capell-reporting.defaults.channels', $route === 'operator' ? ['log'] : ['email']);
        config()->set('capell-reporting.defaults.owner', 'primary');
    }

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($this->emergencyRecords->getRecords())->toBe([])
        ->and($this->reportingRecords->getRecords())->toHaveCount(1)
        ->and($this->reportingRecords->getRecords()[0]->message)->not->toContain('WARM_LOGGER_SECRET')
        ->and($application->make('reporting.warmed-callback'))->toBe($warmed)
        ->and($application->make(LogManager::class))->toBe($logs);
})->with(['factory', 'tap'])->with(['singleton', 'alias', 'instance'])->with(['log', 'operator', 'operator fallback']);

it('isolates container logger resolution while a custom driver is suspended', function (): void {
    $originalApplication = $this->app;
    $logs = $this->reportingLogs;
    $reportingRecords = $this->reportingRecords;
    $emergencyRecords = $this->emergencyRecords;

    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    config()->set('capell-reporting.log_channel', 'delegating');
    config()->set('logging.channels.delegating', ['driver' => 'delegating']);

    $logs->extend('delegating', function (Application $application): LoggerInterface {
        Fiber::suspend();

        return $application->make(LogManager::class)->channel('reporting-test');
    });
    $rebindings = 0;
    $originalApplication->rebinding('log', static function () use (&$rebindings): void {
        $rebindings++;
    });
    $fiber = new Fiber(fn (): DispatchResultData => resolve(DispatchSignalAction::class)->handle(failureSafetySignal()));
    $fiber->start();

    try {
        expect($originalApplication->make('log'))->toBe($logs)
            ->and($originalApplication->make(Application::class))->toBe($originalApplication);
        $logs->channel('reporting-test')->info('Ordinary logging is available.');
    } finally {
        $fiber->resume();
    }

    expect($fiber->getReturn()->status)->toBe(DispatchStatus::Reported)
        ->and($emergencyRecords->getRecords())->toBe([])
        ->and($reportingRecords->getRecords())->toHaveCount(2)
        ->and($rebindings)->toBe(0);
});

it('suppresses dispatch through the application passed to a custom log driver', function (string $phase): void {
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    config()->set('capell-reporting.log_channel', 'recursive');
    config()->set('logging.channels.recursive', ['driver' => 'recursive']);

    $calls = 0;
    $nested = [];
    $records = $this->reportingRecords;
    $this->reportingLogs->extend('recursive', function (Application $application) use (&$calls, &$nested, $records, $phase): Logger {
        $dispatch = static function () use ($application, &$calls, &$nested): void {
            if (++$calls < 4) {
                $nested[] = new DispatchSignalAction($application)->handle(failureSafetySignal('nested-' . $calls));
            }
        };

        if ($phase === 'driver') {
            $dispatch();
        }

        $logger = new Logger('reporting-test', [$records]);
        if ($phase === 'handler') {
            $logger->pushProcessor(static function (LogRecord $record) use ($dispatch): LogRecord {
                $dispatch();

                return $record;
            });
        }

        return $logger;
    });

    expect(resolve(DispatchSignalAction::class)->handle(failureSafetySignal())->status)->toBe(DispatchStatus::Reported)
        ->and($calls)->toBe(1)
        ->and($nested)->toHaveCount(1)
        ->and($nested[0]->status)->toBe(DispatchStatus::Suppressed)
        ->and($nested[0]->reason)->toBe('recursive_dispatch')
        ->and($records->getRecords())->toHaveCount(1);
})->with(['driver', 'handler']);

it('fails safely when the default logger cannot be constructed and allows recovery', function (): void {
    $this->reportingLogs->extend('broken', fn (): never => throw new RuntimeException('password=LOGGER_CONSTRUCTION_SECRET'));
    config()->set('logging.channels.broken', ['driver' => 'broken']);
    config()->set('logging.default', 'broken');

    $result = resolve(DispatchSignalAction::class)->handle(failureSafetySignal());
    expect($this->emergencyRecords->getRecords())->toBe([])
        ->and($result->status)->toBe(DispatchStatus::Failed)
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
