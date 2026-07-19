<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
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
            ->reject(fn (DoctorCheckResultData $check): bool => $check->passed)
            ->map(fn (DoctorCheckResultData $check): string => $check->label)
            ->values()
            ->all();

        return new RuntimeRefreshStageResultData(
            key: 'doctor',
            label: 'Capell Doctor',
            passed: $report->passed(),
            message: $report->passed()
                ? 'All Capell Doctor checks passed.'
                : sprintf('Failed checks: %s.', implode(', ', $failures)),
        );
    }
}
