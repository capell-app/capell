<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics;

use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Support\Facades\Date;
use Override;

/** Adds a "Server load" section to Site Health with one row for each server that has reported. */
final readonly class HostLoadSiteHealthReportExtender implements SiteHealthReportExtender
{
    public function __construct(
        private HostMonitorStore $store,
        private AssessHostLoadAction $assess,
    ) {}

    /** @return list<DiagnosticSectionData> */
    #[Override]
    public function sections(): array
    {
        if (config('capell.health.host.enabled', true) !== true) {
            return [];
        }

        $checks = array_map(fn (string $node): DiagnosticCheckData => $this->check($this->assess->handle($node)), $this->store->nodes());

        if ($checks === []) {
            $checks = [new DiagnosticCheckData(
                status: 'amber',
                label: (string) __('capell-admin::host-monitor.section'),
                detail: (string) __('capell-admin::host-monitor.section_empty'),
            )];
        }

        return [new DiagnosticSectionData((string) __('capell-admin::host-monitor.section'), $checks)];
    }

    private function check(HostAssessmentData $assessment): DiagnosticCheckData
    {
        $sample = $assessment->sample;
        $details = [];

        if ($sample instanceof HostSampleData) {
            $details[] = (string) __('capell-admin::host-monitor.section_detail', [
                'load' => number_format($sample->load5, 2),
                'cores' => $sample->cores,
                'tasks' => $sample->schedulerTasks,
                'processes' => $sample->schedulerProcesses,
            ]);
        }

        foreach ($assessment->reasons as $reason) {
            $details[] = $reason['message'];
        }

        if ($sample instanceof HostSampleData && $sample->topProcesses !== []) {
            $details[] = (string) __('capell-admin::host-monitor.busiest_detail', [
                'processes' => collect($sample->topProcesses)->take(3)->map(
                    static fn (array $process): string => sprintf('%s (%s%%)', $process['command'] !== '' ? $process['command'] : 'pid ' . $process['pid'], number_format($process['cpu'], 0)),
                )->implode(', '),
            ]);
        }

        if ($sample instanceof HostSampleData && $sample->slowestSchedulerTasks !== []) {
            $details[] = (string) __('capell-admin::host-monitor.slowest_detail', [
                'tasks' => collect($sample->slowestSchedulerTasks)->map(
                    static fn (array $task): string => sprintf('%s (%ss)', $task['command'], number_format($task['seconds'], 1)),
                )->implode(', '),
            ]);
        }

        return new DiagnosticCheckData(
            status: match ($assessment->status) {
                HostMonitorStatus::Ok => 'green',
                HostMonitorStatus::Warning => 'amber',
                HostMonitorStatus::Critical => 'red',
            },
            label: $sample->node ?? '',
            detail: implode(' ', $details),
            remediation: $assessment->needsAttention() ? (string) __('capell-admin::host-monitor.remediation') : null,
            generatedAt: $sample instanceof HostSampleData ? Date::createFromTimestamp($sample->takenAt)->format(DATE_ATOM) : null,
        );
    }
}
