<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Reporting;

use Capell\Core\Data\Reporting\DispatchResultData;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Data\Reporting\ReportingOptionsData;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Support\Reporting\OperatorSignalRouter;
use Capell\Core\Support\Reporting\ReportingTransportBoundary;
use Capell\Core\Support\Reporting\SignalDispatchGuard;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class DispatchSignalAction
{
    private const int MAX_ARRAY_CLAIMS = 1000;

    private ReportingTransportBoundary $transports;

    public function __construct(private Container $container, ?ReportingTransportBoundary $transports = null)
    {
        $this->transports = $transports ?? new ReportingTransportBoundary($container);
    }

    public function handle(SignalData|RedactedSignalData $signal): DispatchResultData
    {
        $payload = $signal instanceof RedactedSignalData ? $signal : RedactedSignalData::fromSignal($signal);

        if (! SignalDispatchGuard::enter($this->container)) {
            return new DispatchResultData(DispatchStatus::Suppressed, reason: 'recursive_dispatch');
        }

        try {
            return $this->dispatch($payload);
        } finally {
            SignalDispatchGuard::leave($this->container);
        }
    }

    private function dispatch(RedactedSignalData $signal): DispatchResultData
    {
        try {
            $options = ReportingOptionsData::fromConfiguration($this->container->make(Repository::class)->get('capell-reporting'), $signal);
        } catch (Throwable) {
            return $this->fallback($signal, 'configuration_unavailable');
        }

        if (! $options->enabled) {
            return new DispatchResultData(DispatchStatus::Disabled);
        }

        if ($options->transport === 'operator') {
            try {
                return $this->container->make(OperatorSignalRouter::class)->report($signal, $options);
            } catch (Throwable) {
                return $this->fallback($signal, 'routing_unavailable', $options->logChannel);
            }
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

        $delivery = $options->transport === 'log'
            ? $this->transports->log($signal, $options->logChannel)
            : $this->transports->report($signal, $options->reporters[$options->transport] ?? null);
        if ($delivery->accepted) {
            return new DispatchResultData(DispatchStatus::Reported, $options->transport);
        }

        $result = $this->fallback($signal, 'transport_unavailable', $options->transport === 'log' ? null : $options->logChannel);
        if ($result->status === DispatchStatus::Failed) {
            $this->release($lock);
        }

        return $result;
    }

    private function fallback(RedactedSignalData $signal, string $reason, ?string $channel = null): DispatchResultData
    {
        foreach ($channel === null ? [null] : [$channel, null] as $candidate) {
            if ($this->transports->log($signal, $candidate)->accepted) {
                return new DispatchResultData(DispatchStatus::Fallback, 'log', $reason);
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
