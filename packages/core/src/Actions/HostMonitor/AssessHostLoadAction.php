<?php

declare(strict_types=1);

namespace Capell\Core\Actions\HostMonitor;

use Capell\Core\Data\Diagnostics\HostAssessmentData;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Support\HostMonitor\HostMonitorStore;

/**
 * Turns recent readings into one status for a node.
 *
 * A reading only counts when the last few agree, so a scheduler burst or a
 * single busy minute does not raise an alert. Once raised, the status holds
 * until the same number of readings agree it has gone, so a value hovering
 * around a threshold does not flap.
 */
final readonly class AssessHostLoadAction
{
    public function __construct(private HostMonitorStore $store) {}

    public function handle(?string $node = null): HostAssessmentData
    {
        $node ??= $this->store->node();
        $samples = $this->store->samples($node);
        $latest = $samples === [] ? null : $samples[array_key_last($samples)];

        if (! $latest instanceof HostSampleData) {
            return new HostAssessmentData(HostMonitorStatus::Ok, [], null);
        }

        $state = $this->store->state($node);
        $previous = HostMonitorStatus::tryFrom($state['status']) ?? HostMonitorStatus::Ok;
        $required = max(1, (int) config('capell.health.host.sustained_samples', 5));
        $window = array_slice($samples, -$required);

        $status = $previous;

        if (count($window) >= $required) {
            $ranks = array_map(fn (HostSampleData $sample): int => $this->statusOf($sample)->rank(), $window);

            $status = match (true) {
                min($ranks) >= HostMonitorStatus::Critical->rank() => HostMonitorStatus::Critical,
                min($ranks) >= HostMonitorStatus::Warning->rank() => HostMonitorStatus::Warning,
                max($ranks) === HostMonitorStatus::Ok->rank() => HostMonitorStatus::Ok,
                default => $previous,
            };
        }

        return new HostAssessmentData(
            status: $status,
            reasons: $status === HostMonitorStatus::Ok ? [] : $this->reasonsFor($latest),
            sample: $latest,
            sinceTimestamp: $state['since'],
        );
    }

    public function statusOf(HostSampleData $sample): HostMonitorStatus
    {
        $worst = HostMonitorStatus::Ok;

        foreach ($this->evaluate($sample) as $reason) {
            if ($reason['status']->isAtLeast($worst)) {
                $worst = $reason['status'];
            }
        }

        return $worst;
    }

    /**
     * What is wrong in one reading, worst first.
     *
     * @return list<array{signal: string, status: string, message: string}>
     */
    public function reasonsFor(HostSampleData $sample): array
    {
        $reasons = $this->evaluate($sample);
        usort($reasons, static fn (array $a, array $b): int => $b['status']->rank() <=> $a['status']->rank());

        return array_map(static fn (array $reason): array => [
            'signal' => $reason['signal'],
            'status' => $reason['status']->value,
            'message' => $reason['message'],
        ], $reasons);
    }

    /** @return list<array{signal: string, status: HostMonitorStatus, message: string}> */
    private function evaluate(HostSampleData $sample): array
    {
        $reasons = [];

        $load = $this->grade($sample->loadRatio(), 'load.warning_ratio', 'load.critical_ratio', 0.75, 1.5);

        if ($load instanceof HostMonitorStatus) {
            $reasons[] = ['signal' => 'load', 'status' => $load, 'message' => (string) __('capell-core::health.host_monitor.reasons.load', [
                'load' => number_format($sample->load5, 2),
                'cores' => $sample->cores,
            ])];
        }

        $memoryAvailable = $sample->memoryAvailablePercent();

        if ($memoryAvailable !== null) {
            $memory = $this->gradeBelow($memoryAvailable, 'memory.warning_available_percent', 'memory.critical_available_percent', 15, 7);

            if ($memory instanceof HostMonitorStatus) {
                $reasons[] = ['signal' => 'memory', 'status' => $memory, 'message' => (string) __('capell-core::health.host_monitor.reasons.memory', [
                    'available' => number_format($memoryAvailable, 0),
                ])];
            }
        }

        $swapUsed = $sample->swapUsedPercent();

        if ($swapUsed !== null) {
            $swap = $this->grade($swapUsed, 'swap.warning_used_percent', 'swap.critical_used_percent', 60, 90);

            if ($swap instanceof HostMonitorStatus) {
                $reasons[] = ['signal' => 'swap', 'status' => $swap, 'message' => (string) __('capell-core::health.host_monitor.reasons.swap', [
                    'used' => number_format($swapUsed, 0),
                ])];
            }
        }

        $schedulerCpu = $sample->schedulerCpuRatio();

        if ($schedulerCpu !== null) {
            $cpu = $this->grade($schedulerCpu, 'scheduler.warning_cpu_ratio', 'scheduler.critical_cpu_ratio', 0.3, 0.6);

            if ($cpu instanceof HostMonitorStatus) {
                $reasons[] = ['signal' => 'scheduler_cpu', 'status' => $cpu, 'message' => (string) __('capell-core::health.host_monitor.reasons.scheduler_cpu', [
                    'seconds' => number_format((float) $sample->schedulerCpuSeconds, 0),
                    'processes' => $sample->schedulerProcesses,
                    'percent' => number_format($schedulerCpu * 100, 0),
                ])];
            }
        }

        if ($sample->schedulerWallSeconds !== null) {
            $wall = $this->grade($sample->schedulerWallSeconds, 'scheduler.warning_wall_seconds', 'scheduler.critical_wall_seconds', 50, 90);

            if ($wall instanceof HostMonitorStatus) {
                $reasons[] = ['signal' => 'scheduler_wall', 'status' => $wall, 'message' => (string) __('capell-core::health.host_monitor.reasons.scheduler_wall', [
                    'seconds' => number_format($sample->schedulerWallSeconds, 0),
                ])];
            }
        }

        return $reasons;
    }

    private function grade(float $value, string $warningKey, string $criticalKey, float $warningDefault, float $criticalDefault): ?HostMonitorStatus
    {
        $critical = $this->threshold($criticalKey, $criticalDefault);
        $warning = $this->threshold($warningKey, $warningDefault);

        return match (true) {
            $value >= $critical => HostMonitorStatus::Critical,
            $value >= $warning => HostMonitorStatus::Warning,
            default => null,
        };
    }

    private function gradeBelow(float $value, string $warningKey, string $criticalKey, float $warningDefault, float $criticalDefault): ?HostMonitorStatus
    {
        $critical = $this->threshold($criticalKey, $criticalDefault);
        $warning = $this->threshold($warningKey, $warningDefault);

        return match (true) {
            $value <= $critical => HostMonitorStatus::Critical,
            $value <= $warning => HostMonitorStatus::Warning,
            default => null,
        };
    }

    private function threshold(string $key, float $default): float
    {
        $value = config('capell.health.host.' . $key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
