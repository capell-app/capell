<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Support\HostMonitor\DeferrableTaskPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Makes every scheduled task named in capell.health.host.deferrable_tasks skip
 * its runs while the server is critically busy, so a host needs no code change.
 * Runs as `schedule:run` starts, when every task is already registered.
 *
 * @method static int run(Schedule $schedule)
 */
final class ApplyDeferrableTasksAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly DeferrableTaskPolicy $policy) {}

    /** @return int How many tasks now carry the filter. */
    public function handle(Schedule $schedule): int
    {
        $applied = 0;

        foreach ($schedule->events() as $event) {
            $name = $this->policy->listedNameFor($event);
            if ($name === null) {
                continue;
            }

            $event->skip(fn (): bool => $this->policy->shouldSkip($name, $this->policy->isShared($event)));
            $applied++;
        }

        return $applied;
    }
}
