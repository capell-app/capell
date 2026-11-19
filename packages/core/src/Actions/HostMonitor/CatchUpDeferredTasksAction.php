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
        $today = now()->format('Y-m-d');
        $busy = null;

        foreach ($schedule->events() as $event) {
            $name = $this->policy->listedNameFor($event);
            if ($name === null) {
                continue;
            }

            if (in_array($name, $ran, true)) {
                continue;
            }

            if ($this->store->catchUpPendingSince($name) === null) {
                continue;
            }

            if ($this->store->caughtUpOn($name) === $today) {
                continue;
            }

            $busy ??= $this->policy->isBusy();

            if ($busy && ! $this->pastCatchUpTime()) {
                continue;
            }

            if (! $event->runsInEnvironment($this->app->environment())) {
                continue;
            }

            if (! $event->runsInMaintenanceMode() && $this->app->isDownForMaintenance()) {
                continue;
            }

            $this->store->markCaughtUp($name, $today);
            $this->store->clearCatchUpPending($name);
            $this->start($event);
            $ran[] = $name;
        }

        return $ran;
    }

    private function pastCatchUpTime(): bool
    {
        $at = config('capell.health.host.deferrable_catch_up_at');

        if (! is_string($at) || preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim($at), $match) !== 1) {
            return false;
        }

        return now()->greaterThanOrEqualTo(now()->setTime((int) $match[1], (int) $match[2]));
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
