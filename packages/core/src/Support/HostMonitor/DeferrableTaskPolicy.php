<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Decides which scheduled tasks the host may defer and whether the server is
 * busy right now. The list comes from capell.health.host.deferrable_tasks; an
 * empty list means no task is ever touched.
 *
 * Octane-safe: it holds no state of its own. Everything it remembers lives in
 * the cache store, through HostMonitorStore, and the catch-up marker is bound
 * in the container only for the length of one call.
 */
final readonly class DeferrableTaskPolicy
{
    private const string CATCHING_UP = 'capell.host-monitor.catching-up';

    public function __construct(
        private AssessHostLoadAction $assess,
        private HostMonitorStore $store,
        private Application $app,
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

    /**
     * The listed name this event answers to, or null when it is not deferrable. A task matches by its
     * exact description; a command task also matches by its artisan command name.
     */
    public function listedNameFor(Event $event): ?string
    {
        $listed = $this->listed();

        if ($listed === []) {
            return null;
        }

        $description = is_string($event->description) ? trim($event->description) : '';
        $command = ScheduledTaskName::artisanCommand($event);

        foreach ($listed as $name) {
            if (($description !== '' && $name === $description) || ($command !== null && $name === $command)) {
                return $name;
            }
        }

        return null;
    }

    /** A task that runs on one server only has one record for the whole installation. */
    public function isShared(Event $event): bool
    {
        return $event->onOneServer;
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

    /**
     * Called as the scheduler is about to run a listed task: skip it while busy, remember that it owes a run.
     * A skip that cannot be remembered is not a skip: the task runs rather than being lost.
     */
    public function shouldSkip(string $name, bool $shared = false): bool
    {
        if ($this->app->bound(self::CATCHING_UP)) {
            return false;
        }

        if (! $this->isBusy()) {
            $this->store->clearCatchUpPending($name, $shared);

            return false;
        }

        // A run recorded this minute (another server won a task that runs on one server only) leaves nothing owed.
        $lastRun = $this->store->lastRunAt($name, $shared);

        if ($lastRun !== null && $lastRun >= now()->startOfMinute()->getTimestamp()) {
            return true;
        }

        if ($this->store->catchUpPendingSince($name, $shared) === null) {
            $this->store->markCatchUpPending($name, now()->getTimestamp(), $shared);
        }

        return $this->store->catchUpPendingSince($name, $shared) !== null;
    }

    /** Remembers that a listed task ran, so a skip recorded around the same time is not caught up again. */
    public function recordRun(Event $event): void
    {
        $name = $this->listedNameFor($event);

        if ($name !== null) {
            $this->store->markRan($name, now()->getTimestamp(), $this->isShared($event));
        }
    }

    /** True when the task ran at or after the minute its owed run was recorded. */
    public function ranSince(string $name, bool $shared, int $pendingSince): bool
    {
        $lastRun = $this->store->lastRunAt($name, $shared);

        return $lastRun !== null && $lastRun >= $pendingSince - $pendingSince % 60;
    }

    /** The event's own conditions (when, skip, between) without this host's deferral filter. */
    public function filtersPassForCatchUp(Event $event): bool
    {
        $this->app->instance(self::CATCHING_UP, true);

        try {
            return $event->filtersPass($this->app);
        } finally {
            $this->app->forgetInstance(self::CATCHING_UP);
        }
    }
}
