<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics;

use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Actions\HostMonitor\HostLoadTrendAction;
use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Data\Diagnostics\HostLoadTrendData;
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
        private HostLoadTrendAction $trends,
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

        $node = $sample instanceof HostSampleData ? $sample->node : '';

        if ($node !== '') {
            array_push($details, ...$this->trendDetails($node));
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

    /** @return list<string> */
    private function trendDetails(string $node): array
    {
        $day = $this->trends->handle($node, 1);
        $week = $this->trends->handle($node, 7);

        if (! $week instanceof HostLoadTrendData) {
            return [(string) __('capell-admin::host-monitor.trend_none')];
        }

        $details = [];

        if ($day instanceof HostLoadTrendData) {
            $details[] = $this->trendLine('capell-admin::host-monitor.trend_day', $day);
        }

        $details[] = $this->trendLine('capell-admin::host-monitor.trend_week', $week);

        if ($week->busiestTasks !== []) {
            $details[] = (string) __('capell-admin::host-monitor.trend_busiest_tasks', [
                'tasks' => collect($week->busiestTasks)->map(
                    static fn (array $task): string => sprintf('%s (%ss)', $task['command'], number_format($task['seconds'], 1)),
                )->implode(', '),
            ]);
        }

        return [...$details, ...$this->guidance($week)];
    }

    private function trendLine(string $key, HostLoadTrendData $trend): string
    {
        $window = $trend->busiestWindow;

        if ($key === 'capell-admin::host-monitor.trend_week' && $window === null) {
            $key = 'capell-admin::host-monitor.trend_week_short';
        }

        return (string) __($key, [
            'average' => $this->percent($trend->averageRatio),
            'peak' => $this->percent($trend->peakRatio),
            'peak_at' => Date::createFromTimestamp($trend->peakAt, (string) config('app.timezone', 'UTC'))->format('D j M, H:i'),
            'from' => sprintf('%02d:00', $window['from'] ?? 0),
            'to' => sprintf('%02d:00', $window['to'] ?? 0),
        ]);
    }

    /**
     * Plain-English advice from what the server measured over the week, using the same limits that raise alerts.
     *
     * @return list<string>
     */
    private function guidance(HostLoadTrendData $week): array
    {
        $lines = [];
        $criticalRatio = (float) config('capell.health.host.load.critical_ratio', 1.5);
        $warningRatio = (float) config('capell.health.host.load.warning_ratio', 0.75);

        if ($week->peakRatio >= $criticalRatio) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_peak_critical', ['peak' => $this->percent($week->peakRatio)]);
        } elseif ($week->peakRatio >= $warningRatio) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_peak_warning', ['peak' => $this->percent($week->peakRatio)]);
        }

        if ($week->lowestMemoryAvailablePercent !== null && $week->lowestMemoryAvailablePercent <= (float) config('capell.health.host.memory.warning_available_percent', 15)) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_memory', ['percent' => number_format($week->lowestMemoryAvailablePercent, 0)]);
        }

        if ($week->peakSwapUsedPercent !== null && $week->peakSwapUsedPercent >= (float) config('capell.health.host.swap.warning_used_percent', 60)) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_swap', ['percent' => number_format($week->peakSwapUsedPercent, 0)]);
        }

        if ($week->peakQueueDepth !== null && $week->peakQueueDepth >= (int) config('capell.health.host.history.queue_backlog_warning', 500)) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_queue', ['jobs' => number_format($week->peakQueueDepth)]);
        }

        // Only claim spare room once a day of readings backs it up.
        if ($lines === [] && $week->hours >= 24 && $week->peakRatio < $warningRatio / 2) {
            $lines[] = (string) __('capell-admin::host-monitor.guidance_room', ['ceiling' => $this->percent($warningRatio / 2)]);
        }

        return $lines;
    }

    private function percent(float $ratio): string
    {
        return number_format($ratio * 100, 0) . '%';
    }
}
