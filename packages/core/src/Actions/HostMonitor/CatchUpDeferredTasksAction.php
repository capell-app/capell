<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Support\HostMonitor\DeferrableTaskPolicy;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Runs a listed task that skipped runs while the server was busy. The catch-up
 * happens once the server is no longer busy, or at the configured time of day,
 * whichever comes first, and never more than once a day for a task. A task that
 * came due and ran on its own after the busy spell has nothing left to catch up.
 *
 * @method static list<string> run(Schedule $schedule)
 */
final class CatchUpDeferredTasksAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly DeferrableTaskPolicy $policy,
        private readonly HostMonitorStore $store,
        private readonly Application $app,
        private readonly Dispatcher $events,
    ) {}

    /** @return list<string> The tasks run as a catch-up. */
    public function handle(Schedule $schedule): array
    {
        $ran = [];
        $busy = null;

        foreach ($schedule->events() as $event) {
            $name = $this->policy->listedNameFor($event);
            if ($name === null) {
                continue;
            }

            if (in_array($name, $ran, true)) {
                continue;
            }

            $shared = $this->policy->isShared($event);

            $pendingSince = $this->store->catchUpPendingSince($name, $shared);

            if ($pendingSince === null) {
                continue;
            }

            // It ran on its own since it was marked owed, here or, for a one-server task, on another node.
            if ($this->policy->ranSince($name, $shared, $pendingSince)) {
                $this->store->clearCatchUpPending($name, $shared);

                continue;
            }

            // The event's own time zone decides when the catch-up time has passed and what "today" is.
            $now = now($event->timezone);
            $busy ??= $this->policy->isBusy();

            if ($busy && ! $this->pastCatchUpTime($now)) {
                continue;
            }

            if (! $event->runsInEnvironment($this->app->environment())) {
                continue;
            }

            if (! $event->runsInMaintenanceMode() && $this->app->isDownForMaintenance()) {
                continue;
            }

            if (! $this->policy->filtersPassForCatchUp($event)) {
                continue;
            }

            $date = $now->format('Y-m-d');

            if (! $this->store->claimCatchUp($name, $date, $shared)) {
                continue;
            }

            // Another server already holds this minute's turn for a task that runs on one server only.
            if ($shared && ! $schedule->serverShouldRun($event, $now)) {
                $this->store->releaseCatchUp($name, $date, $shared);

                continue;
            }

            $this->start($event);

            // withoutOverlapping() held it back: it did not run, so it still owes its run and today's claim goes back.
            if ($event->skippedBecauseOverlapping) {
                $this->store->releaseCatchUp($name, $date, $shared);

                continue;
            }

            $this->store->clearCatchUpPending($name, $shared);
            $ran[] = $name;
        }

        return $ran;
    }

    private function pastCatchUpTime(Carbon $now): bool
    {
        $at = config('capell.health.host.deferrable_catch_up_at');

        if (! is_string($at) || preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim($at), $match) !== 1) {
            return false;
        }

        return $now->greaterThanOrEqualTo($now->copy()->setTime((int) $match[1], (int) $match[2]));
    }

    private function start(Event $event): void
    {
        $started = microtime(true);
        $this->events->dispatch(new ScheduledTaskStarting($event));

        try {
            $event->run($this->app);
            $this->events->dispatch(new ScheduledTaskFinished($event, round(microtime(true) - $started, 2)));
        } catch (Throwable $throwable) {
            $this->events->dispatch(new ScheduledTaskFailed($event, $throwable));
            report($throwable);
        }
    }
}
