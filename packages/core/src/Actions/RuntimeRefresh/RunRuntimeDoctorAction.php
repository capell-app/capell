<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorWarningSummaryData;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunRuntimeDoctorAction
{
    use AsFake;
    use AsObject;

    public function handle(): RuntimeRefreshStageResultData
    {
        $report = BuildDoctorReportAction::run();
        $failures = $report->checks
            ->filter(fn (DoctorCheckResultData $check): bool => $check->outcome() === DoctorCheckOutcome::Failed)
            ->map(fn (DoctorCheckResultData $check): string => $check->label)
            ->values()
            ->all();

        return new RuntimeRefreshStageResultData(
            key: 'doctor',
            label: 'Capell Doctor',
            passed: $report->passed(),
            message: match (true) {
                ! $report->passed() => (string) __('capell-core::health.doctor.runtime_stage_failed', ['checks' => implode(', ', $failures)]),
                $report->warningCount() > 0 => (string) __('capell-core::health.doctor.runtime_stage_passed_with_warnings', [
                    'count' => $report->warningCount(),
                    'command' => DoctorWarningSummaryData::LIST_COMMAND,
                ]),
                default => (string) __('capell-core::health.doctor.runtime_stage_passed'),
            },
        );
    }
}
