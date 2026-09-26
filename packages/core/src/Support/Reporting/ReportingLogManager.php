<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Data\Reporting\SignalData;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ReportingLogManager extends LogManager
{
    public function __construct(LogManager $logs)
    {
        $application = clone $logs->app;
        throw_unless($application instanceof Container, RuntimeException::class, 'Reporting requires an isolatable application container.');
        parent::__construct($application);

        // Custom drivers and factories receive this isolated container. Replacing
        // its roots without rebinding callbacks leaves ordinary logging untouched.
        unset($application['app'], $application['log']);
        $application->instance('app', $application);
        $application->alias('app', Container::class);
        $application->instance('log', $this);
        $application->alias('log', LogManager::class);
        $application->alias('log', LoggerInterface::class);

        // A warmed factory or tap may retain the host manager even after rebinding
        // the container. Rebuild callbacks inside the clone, including stack members.
        foreach ($application->make(Repository::class)->get('logging.channels', []) as $configuration) {
            if (! is_array($configuration)) {
                continue;
            }

            $factory = $configuration['via'] ?? null;
            if (is_string($factory)) {
                $application->forgetInstance($application->getAlias($factory));
            }

            foreach ($configuration['tap'] ?? [] as $tap) {
                if (is_string($tap)) {
                    [$callback] = $this->parseTap($tap);
                    $application->forgetInstance($application->getAlias($callback));
                }
            }
        }

        // A cached stack can already contain an emergency logger from a failed member.
        // Rebuild from configuration so every member uses the protected resolution path.
        $this->sharedContext = $logs->sharedContext;

        foreach ($logs->customCreators as $driver => $creator) {
            $this->extend($driver, $creator);
        }
    }

    public function report(SignalData $signal, ?string $channel): void
    {
        // The private container must not become a new dispatch origin inside a driver or handler.
        throw_unless(SignalDispatchGuard::enter($this->app), RuntimeException::class, 'Reporting log delivery is already active.');

        try {
            $this->channel($channel)->log($signal->severity->value, $signal->toJson());
        } finally {
            SignalDispatchGuard::leave($this->app);
        }
    }

    #[Override]
    protected function createEmergencyLogger(): never
    {
        // Laravel otherwise writes the raw construction exception before returning an emergency logger.
        throw new RuntimeException('Reporting log channel is unavailable.');
    }
}
