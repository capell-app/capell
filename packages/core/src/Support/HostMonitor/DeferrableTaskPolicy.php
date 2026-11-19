<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Decides which scheduled tasks the host may defer and whether the server is
 * busy right now. The list comes from capell.health.host.deferrable_tasks; an
 * empty list means no task is ever touched.
 *
 * Octane-safe: it holds no state of its own. Everything it remembers lives in
 * the cache store, through HostMonitorStore.
 */
final readonly class DeferrableTaskPolicy
{
    public function __construct(
        private AssessHostLoadAction $assess,
        private HostMonitorStore $store,
    ) {}

    /** @return list<string> */
    public function listed(): array
    {
        $configured = config('capell.health.host.deferrable_tasks', []);

        if (config('capell.health.host.enabled', true) !== true || ! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => is_string($name) ? trim($name) : '', $configured),
            static fn (string $name): bool => $name !== '',
        )));
    }

    /** The listed name this event answers to, or null when it is not deferrable. */
    public function listedNameFor(Event $event): ?string
    {
        $listed = $this->listed();

        if ($listed === []) {
            return null;
        }

        $description = is_string($event->description) ? trim($event->description) : '';

        foreach ($listed as $name) {
            if ($name === $description || $name === ScheduledTaskName::of($event)) {
                return $name;
            }
        }

        return null;
    }

    /** True while the monitor reports this server as critically busy. No reading, or a failing one, is not busy. */
    public function isBusy(): bool
    {
        try {
            return $this->assess->handle()->status === HostMonitorStatus::Critical;
        } catch (Throwable) {
            return false;
        }
    }

    /** Called as the scheduler is about to run a listed task: skip it while busy, remember that it owes a run. */
    public function shouldSkip(string $name): bool
    {
        if (! $this->isBusy()) {
            $this->store->clearCatchUpPending($name);

            return false;
        }

        if ($this->store->catchUpPendingSince($name) === null) {
            $this->store->markCatchUpPending($name, now()->getTimestamp());
        }

        return true;
    }
}
