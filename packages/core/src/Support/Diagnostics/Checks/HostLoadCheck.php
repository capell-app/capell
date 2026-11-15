<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\HostMonitor\AssessHostLoadAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

/**
 * Reports whether this server is under sustained load, from the readings the
 * scheduler records every minute. Advisory only: a busy server still serves
 * pages, so this warns and never blocks a deploy.
 */
final class HostLoadCheck extends AbstractDoctorCheck
{
    public function __construct(private readonly AssessHostLoadAction $assess) {}

    protected function id(): string
    {
        return 'core.host.load';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $label = (string) __('capell-core::health.host_monitor.label');

        if (config('capell.health.host.enabled', true) !== true) {
            return new DoctorCheckResultData(label: $label, passed: true, message: (string) __('capell-core::health.host_monitor.disabled'), severity: $this->severity());
        }

        $assessment = $this->assess->handle();
        $sample = $assessment->sample;

        if (! $sample instanceof HostSampleData) {
            return new DoctorCheckResultData(label: $label, passed: true, message: (string) __('capell-core::health.host_monitor.no_samples'), severity: $this->severity());
        }

        $evidence = [
            'node' => $sample->node,
            'status' => $assessment->status->value,
            'cores' => $sample->cores,
            'load5' => $sample->load5,
            'sampled_at' => $sample->takenAt,
            'signals' => array_column($assessment->reasons, 'signal'),
        ];

        if (! $assessment->needsAttention()) {
            return new DoctorCheckResultData(
                label: $label,
                passed: true,
                message: (string) __('capell-core::health.host_monitor.healthy', ['load' => number_format($sample->load5, 2), 'cores' => $sample->cores]),
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        return new DoctorCheckResultData(
            label: $label,
            passed: false,
            message: implode(' ', array_column($assessment->reasons, 'message')),
            remediation: (string) __('capell-core::health.host_monitor.remediation'),
            severity: $this->severity(),
            evidence: $evidence,
        );
    }
}
