<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Throwable;

/**
 * Takes one reading, assesses the node and, when the status changed or a
 * reminder is due, hands the change to the notifier. Runs inside the scheduler
 * process as a closure, so the monitor itself never starts another PHP process.
 */
final readonly class RecordHostSampleAction
{
    public function __construct(
        private HostMetricsSource $source,
        private HostMonitorStore $store,
        private AssessHostLoadAction $assess,
        private NotifyHostMonitorAction $notify,
    ) {}

    public function handle(): ?HostAssessmentData
    {
        if (config('capell.health.host.enabled', true) !== true) {
            return null;
        }

        try {
            return $this->record();
        } catch (Throwable $throwable) {
            // A failing monitor must not fail the scheduler run it is part of.
            report($throwable);

            return null;
        }
    }

    private function record(): HostAssessmentData
    {
        $sample = $this->source->read();

        $this->store->pushSample($sample);

        $assessment = $this->assess->handle($sample->node);
        $state = $this->store->state($sample->node);
        $previous = HostMonitorStatus::tryFrom($state['status']) ?? HostMonitorStatus::Ok;

        if ($assessment->status !== $previous) {
            $this->store->putState($sample->node, [
                'status' => $assessment->status->value,
                'since' => now()->getTimestamp(),
                'notified_at' => $this->notify->handle($assessment, $previous, $state['notified_at']),
            ]);
        } elseif ($assessment->needsAttention()) {
            $notifiedAt = $this->notify->remind($assessment, $state['notified_at']);

            if ($notifiedAt !== $state['notified_at']) {
                $this->store->putState($sample->node, [...$state, 'notified_at' => $notifiedAt]);
            }
        }

        return $assessment;
    }
}
