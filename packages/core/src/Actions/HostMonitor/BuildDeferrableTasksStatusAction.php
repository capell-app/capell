<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\DeferrableTasksStatusData;
use Capell\Core\Support\HostMonitor\DeferrableTaskPolicy;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Says which scheduled tasks the host may defer and which of them have skipped
 * a run and are waiting for their catch-up.
 *
 * @method static DeferrableTasksStatusData run()
 */
final class BuildDeferrableTasksStatusAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly DeferrableTaskPolicy $policy,
        private readonly HostMonitorStore $store,
    ) {}

    public function handle(): DeferrableTasksStatusData
    {
        $tasks = $this->policy->listed();

        return new DeferrableTasksStatusData(
            $tasks,
            array_values(array_filter($tasks, fn (string $task): bool => $this->store->catchUpPendingSince($task) !== null || $this->store->catchUpPendingSince($task, true) !== null)),
        );
    }
}
