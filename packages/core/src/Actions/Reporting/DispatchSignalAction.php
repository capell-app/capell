<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\DispatchResultData;
use Capell\Core\Data\Reporting\ReportingOptionsData;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Support\Reporting\LogChannelReporter;
use Capell\Core\Support\Reporting\SignalDispatchGuard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use RuntimeException;
use Throwable;

final readonly class DispatchSignalAction
{
    private const int MAX_ARRAY_CLAIMS = 1000;

    public function __construct(private Container $container) {}

    public function handle(SignalData $signal): DispatchResultData
    {
        if (! SignalDispatchGuard::enter($this->container)) {
            return new DispatchResultData(DispatchStatus::Suppressed, reason: 'recursive_dispatch');
        }

        try {
            return $this->dispatch($signal);
        } finally {
            SignalDispatchGuard::leave($this->container);
        }
    }

    private function dispatch(SignalData $signal): DispatchResultData
    {
        try {
            $options = ReportingOptionsData::fromConfiguration($this->container->make(Repository::class)->get('capell-reporting'), $signal);
        } catch (Throwable) {
            return $this->fallback($signal, 'configuration_unavailable');
        }

        if (! $options->enabled) {
            return new DispatchResultData(DispatchStatus::Disabled);
        }

        $lock = null;
        try {
            if ($options->cooldownSeconds > 0) {
                $store = $this->container->make(Factory::class)->store($options->cacheStore)->getStore();
                throw_unless($store instanceof LockProvider, RuntimeException::class, 'Reporting requires an atomic lock provider.');

                $key = 'capell:reporting:' . $signal->fingerprint();
                if ($store instanceof ArrayStore) {
                    $this->pruneArrayClaims($store, $key);
                }

                $lock = $store->lock($key, $options->cooldownSeconds);
                if (! $lock->get()) {
                    return new DispatchResultData(DispatchStatus::Suppressed, $options->transport);
                }
            }
        } catch (Throwable) {
            $result = $this->fallback($signal, 'deduplication_unavailable', $options->logChannel);
            if ($result->status === DispatchStatus::Failed) {
                $this->release($lock);
            }

            return $result;
        }

        try {
            $reporter = $options->transport === 'log'
                ? $this->logReporter($options->logChannel)
                : $this->resolveReporter($options);
            $reporter->report($signal);

            return new DispatchResultData(DispatchStatus::Reported, $options->transport);
        } catch (Throwable) {
            $result = $this->fallback($signal, 'transport_unavailable', $options->transport === 'log' ? null : $options->logChannel);
            if ($result->status === DispatchStatus::Failed) {
                $this->release($lock);
            }

            return $result;
        }
    }

    private function resolveReporter(ReportingOptionsData $options): Reporter
    {
        $binding = $options->reporters[$options->transport] ?? null;
        throw_if(! is_string($binding) || $binding === '', RuntimeException::class, 'Reporting transport is not registered.');

        $reporter = $this->container->make($binding);
        throw_unless($reporter instanceof Reporter, RuntimeException::class, 'Reporting transport must implement Reporter.');

        return $reporter;
    }

    private function logReporter(?string $channel): LogChannelReporter
    {
        // Reject unknown channels before Laravel silently substitutes its emergency logger.
        throw_if($channel !== null && ! is_array($this->container->make(Repository::class)->get('logging.channels.' . $channel)), RuntimeException::class, 'Reporting log channel is not configured.');

        return new LogChannelReporter($this->container->make(LogManager::class), $channel);
    }

    private function fallback(SignalData $signal, string $reason, ?string $channel = null): DispatchResultData
    {
        foreach ($channel === null ? [null] : [$channel, null] as $candidate) {
            try {
                $this->logReporter($candidate)->report($signal);

                return new DispatchResultData(DispatchStatus::Fallback, 'log', $reason);
            } catch (Throwable) {
                // Exception messages may contain credentials; never forward them to another transport.
            }
        }

        return new DispatchResultData(DispatchStatus::Failed, 'log', 'log_unavailable');
    }

    private function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable) {
            // An unavailable cache must not replace the original failure. The claim expires naturally.
        }
    }

    private function pruneArrayClaims(ArrayStore $store, string $key): void
    {
        $active = 0;

        // ArrayStore expiry and Octane's cache flush leave lock entries in worker memory.
        foreach ($store->locks as $name => $claim) {
            if (! str_starts_with($name, 'capell:reporting:')) {
                continue;
            }

            if ($claim['expiresAt'] !== null && ! $claim['expiresAt']->isFuture()) {
                unset($store->locks[$name]);
            } else {
                $active++;
            }
        }

        throw_if($active >= self::MAX_ARRAY_CLAIMS && ! isset($store->locks[$key]), RuntimeException::class, 'Reporting array claim capacity is exhausted.');
    }
}
