<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Contracts\Health\HostBusyGate;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Override;
use Throwable;

final readonly class HostLoadGate implements HostBusyGate
{
    public function __construct(
        private AssessHostLoadAction $assess,
        private HostMonitorStore $store,
    ) {}

    #[Override]
    public function isBusy(): bool
    {
        if (config('capell.health.host.enabled', true) !== true || config('capell.health.host.defer.enabled', false) !== true) {
            return false;
        }

        try {
            return $this->assess->handle()->status === HostMonitorStatus::Critical;
        } catch (Throwable) {
            return false;
        }
    }

    #[Override]
    public function shouldDefer(string $task): bool
    {
        if (! $this->isBusy()) {
            $this->store->clearDeferred($task);

            return false;
        }

        $now = now()->getTimestamp();
        $since = $this->store->deferredSince($task);

        if ($since === null) {
            $this->store->markDeferred($task, $now);

            return true;
        }

        $limit = max(1, (int) config('capell.health.host.defer.max_minutes', 120)) * 60;

        if ($now - $since >= $limit) {
            // Released: it runs now and, if the server is still busy, waits a fresh limit.
            $this->store->clearDeferred($task);

            return false;
        }

        return true;
    }
}
