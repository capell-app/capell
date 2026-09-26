<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Data\Reporting\SignalData;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ReportingLogManager extends LogManager
{
    private readonly ReportingLoggerBoundary $boundary;

    public function __construct(LogManager $logs)
    {
        $application = clone $logs->app;
        throw_unless($application instanceof Container, RuntimeException::class, 'Reporting requires an isolatable application container.');
        parent::__construct($application);

        $configuration = $application->make(Repository::class);
        $events = $application->make(Dispatcher::class);
        $environment = $application->bound('env') ? $application->make('env') : null;

        // Rebuild the entire callback dependency graph: a warmed dependency can
        // retain the host logger even when its factory or tap is reconstructed.
        // Instance-only dependencies cannot be rebuilt and safely fail delivery.
        $application->forgetInstances();

        // Custom drivers and factories receive this isolated container. Replacing
        // its roots without rebinding callbacks leaves ordinary logging untouched.
        unset($application['app'], $application['log'], $application['config'], $application['events'], $application['env']);
        $application->instance('app', $application);
        $application->alias('app', Container::class);
        $application->instance('log', $this);
        $application->alias('log', LogManager::class);
        $application->alias('log', LoggerInterface::class);
        $application->instance('config', $configuration);
        $application->instance('events', $events);
        if (is_string($environment)) {
            $application->instance('env', $environment);
        }

        $this->boundary = new ReportingLoggerBoundary($this, $application);
        $this->boundary->guardContainer();

        // A cached stack can already contain an emergency logger from a failed member.
        // Rebuild from configuration so every member uses the protected resolution path.
        $this->sharedContext = $logs->sharedContext;

        foreach ($logs->customCreators as $driver => $creator) {
            $this->extend($driver, $creator);
        }
    }

    public function report(SignalData $signal, ?string $channel): void
    {
        $this->boundary->guard(function () use ($signal, $channel): void {
            // The private container must not become a new dispatch origin inside a driver or handler.
            throw_unless(SignalDispatchGuard::enter($this->app), RuntimeException::class, 'Reporting log delivery is already active.');

            try {
                $this->channel($channel)->log($signal->severity->value, $signal->toJson());
            } finally {
                SignalDispatchGuard::leave($this->app);
            }
        });
    }

    /** @param array<string, mixed>|null $config */
    #[Override]
    protected function get($name, ?array $config = null)
    {
        return $this->boundary->guard(fn (): LoggerInterface => $this->channels[$name] ?? with($this->resolve($name, $config), function (LoggerInterface $logger) use ($name): LoggerInterface {
            $loggerWithContext = $this->tap(
                $name,
                new Logger($logger, $this->app->make(Dispatcher::class)),
            )->withContext($this->sharedContext);

            if (method_exists($loggerWithContext->getLogger(), 'pushProcessor')) {
                $loggerWithContext->pushProcessor($this->app->make(ContextLogProcessor::class));
            }

            return $this->channels[$name] = $loggerWithContext;
        }));
    }

    /** @param array<string, mixed> $config */
    #[Override]
    protected function callCustomCreator(array $config)
    {
        $creator = $this->customCreators[$config['driver']];

        return $this->boundary->guardCallback($creator, fn (): mixed => $creator($this->app, $config));
    }

    /** @param array<string, mixed> $config */
    #[Override]
    protected function createCustomDriver(array $config)
    {
        $factory = is_callable($via = $config['via']) ? $via : $this->app->make($via);

        return $this->boundary->guardCallback($factory, static fn (): mixed => $factory($config));
    }

    #[Override]
    protected function tap($name, Logger $logger)
    {
        foreach ($this->configurationFor($name)['tap'] ?? [] as $tap) {
            [$class, $arguments] = $this->parseTap($tap);
            $callback = $this->app->make($class);
            $this->boundary->guardCallback($callback, static fn (): mixed => $callback->__invoke($logger, ...explode(',', $arguments)));
        }

        return $logger;
    }

    #[Override]
    protected function createEmergencyLogger(): never
    {
        // Laravel otherwise writes the raw construction exception before returning an emergency logger.
        throw new RuntimeException('Reporting log channel is unavailable.');
    }
}
