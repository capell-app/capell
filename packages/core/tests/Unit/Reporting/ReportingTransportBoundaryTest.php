<?php

declare(strict_types=1);

use Capell\Core\Actions\Reporting\DispatchSignalAction;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Models\ReportingIncident;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class GlobalReportingLoggerFactory
{
    public function __invoke(): LoggerInterface
    {
        $applicationResolver = 'app';

        return $applicationResolver(LogManager::class)->channel('boundary-broken');
    }
}

final class FacadeReportingLoggerFactory
{
    public function __invoke(): LoggerInterface
    {
        return Log::channel('boundary-broken');
    }
}

final class StaticReportingLoggerFactory
{
    public static ?LogManager $logs = null;

    public function __invoke(): LoggerInterface
    {
        return self::$logs?->channel('boundary-broken') ?? throw new RuntimeException('Static logger is unavailable.');
    }
}

final class CallbackReportingLoggerFactory
{
    public ?LogManager $logs = null;

    public function __invoke(): LoggerInterface
    {
        return $this->logs?->channel('boundary-broken') ?? throw new RuntimeException('Callback logger is unavailable.');
    }
}

final class CallbackReportingLoggerTap
{
    public function __construct(private readonly LogManager $logs) {}

    public function __invoke(): void
    {
        $this->logs->channel('boundary-broken');
    }
}

beforeEach(function (): void {
    $this->boundaryLog = storage_path('framework/testing/reporting-boundary-' . bin2hex(random_bytes(8)) . '.log');
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

    $logs->extend('boundary-broken', fn (): never => throw new RuntimeException('password=BOUNDARY_TRANSPORT_SECRET'));

    $this->app->instance('log', $logs);
    $this->app->instance(LogManager::class, $logs);

    $this->reportingLogs = $logs;
    Log::clearResolvedInstance('log');

    config()->set('logging.default', 'boundary-safe');
    config()->set('logging.channels.boundary-safe', ['driver' => 'single', 'path' => $this->boundaryLog]);
    config()->set('logging.channels.boundary-broken', ['driver' => 'boundary-broken']);
    config()->set('capell-reporting', require __DIR__ . '/../../../config/capell-reporting.php');
    config()->set('capell-reporting.cache_store', 'array');
    config()->set('capell-reporting.log_channel', 'selected');
});

afterEach(function (): void {
    StaticReportingLoggerFactory::$logs = null;
    Log::clearResolvedInstance('log');

    foreach ([$this->boundaryLog, $this->boundaryLog . '.selected'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('contains every callback-capable logger resolution route at one guarded boundary', function (string $resolution, string $route): void {
    $logs = $this->reportingLogs;

    if ($resolution === 'captured driver') {
        $logs->extend('selected-driver', fn (): LoggerInterface => $logs->channel('boundary-broken'));
        config()->set('logging.channels.selected', ['driver' => 'selected-driver']);
    } elseif ($resolution === 'captured factory') {
        config()->set('logging.channels.selected', ['driver' => 'custom', 'via' => fn (): LoggerInterface => $logs->channel('boundary-broken')]);
    } elseif ($resolution === 'global app factory') {
        config()->set('logging.channels.selected', ['driver' => 'custom', 'via' => GlobalReportingLoggerFactory::class]);
    } elseif ($resolution === 'facade factory') {
        config()->set('logging.channels.selected', ['driver' => 'custom', 'via' => FacadeReportingLoggerFactory::class]);
    } elseif ($resolution === 'static factory') {
        StaticReportingLoggerFactory::$logs = $logs;
        config()->set('logging.channels.selected', ['driver' => 'custom', 'via' => StaticReportingLoggerFactory::class]);
    } elseif ($resolution === 'tap') {
        $this->app->instance('reporting.boundary-tap', new CallbackReportingLoggerTap($logs));
        config()->set('logging.channels.selected', ['driver' => 'single', 'path' => $this->boundaryLog . '.selected', 'tap' => ['reporting.boundary-tap']]);
    } elseif ($resolution === 'stack member') {
        config()->set('logging.channels.selected-member', ['driver' => 'custom', 'via' => fn (): LoggerInterface => $logs->channel('boundary-broken')]);
        config()->set('logging.channels.selected', ['driver' => 'stack', 'channels' => ['selected-member']]);
    } elseif ($resolution === 'Monolog handler') {
        config()->set('logging.channels.selected', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    } elseif ($resolution === 'custom formatter') {
        config()->set('logging.channels.selected', ['driver' => 'single', 'path' => $this->boundaryLog . '.selected', 'formatter' => LineFormatter::class]);
    } elseif ($resolution === 'exception-swallowing stack') {
        config()->set('logging.channels.selected', ['driver' => 'stack', 'channels' => ['boundary-safe'], 'ignore_exceptions' => true]);
    } else {
        $this->app->bind('reporting.boundary-factory', static fn (): CallbackReportingLoggerFactory => new CallbackReportingLoggerFactory);
        if ($resolution === 'resolving callback') {
            $this->app->resolving('reporting.boundary-factory', static function (CallbackReportingLoggerFactory $factory) use ($logs): void {
                $factory->logs = $logs;
            });
        } else {
            $this->app->extend('reporting.boundary-factory', static function (CallbackReportingLoggerFactory $factory) use ($logs): CallbackReportingLoggerFactory {
                $factory->logs = $logs;

                return $factory;
            });
        }

        config()->set('logging.channels.selected', ['driver' => 'custom', 'via' => 'reporting.boundary-factory']);
    }

    if ($route === 'default log') {
        config()->set('logging.default', 'selected');
        config()->set('capell-reporting.log_channel');
    } elseif ($route !== 'selected log') {
        config()->set('capell-reporting.defaults.transport', 'operator');
        config()->set('capell-reporting.defaults.channels', $route === 'operator log' ? ['log', 'health'] : ['email']);
        config()->set('capell-reporting.defaults.owner', 'primary');
    }

    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Failed.', 'Inspect.', hash('sha256', $resolution . '-' . $route));
    $result = resolve(DispatchSignalAction::class)->handle($signal);
    $log = is_file($this->boundaryLog) ? (string) file_get_contents($this->boundaryLog) : '';

    expect($result->status)->toBe($route === 'default log' ? DispatchStatus::Failed : DispatchStatus::Fallback)
        ->and($this->emergencyRecords->getRecords())->toBe([])
        ->and(Container::getInstance())->toBe($this->app)
        ->and($log)->not->toContain('BOUNDARY_TRANSPORT_SECRET');

    if ($route === 'default log') {
        expect($result->reason)->toBe('log_unavailable')
            ->and($log)->toBe('');
    } else {
        expect($log)->toContain('runtime.failed');
    }

    if (str_starts_with($route, 'operator')) {
        $deliveries = ReportingIncident::query()->findOrFail($signal->fingerprint())->deliveries;
        expect($deliveries)->toMatchArray($route === 'operator log'
            ? ['log' => 'unavailable', 'health' => 'delivered', 'fallback_log' => 'delivered']
            : ['email' => 'disabled', 'fallback_log' => 'delivered']);
    }
})->with([
    'captured driver',
    'captured factory',
    'global app factory',
    'facade factory',
    'static factory',
    'tap',
    'stack member',
    'Monolog handler',
    'custom formatter',
    'exception-swallowing stack',
    'resolving callback',
    'extender callback',
])->with(['selected log', 'default log', 'operator log', 'operator fallback']);
