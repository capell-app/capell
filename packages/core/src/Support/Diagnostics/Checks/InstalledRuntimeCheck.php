<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Packages\FindUnmetPackageRequirementsAction;
use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\PackageRequirementData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Override;

final class InstalledRuntimeCheck implements DoctorCheck
{
    public function __construct(private readonly InstalledRuntimeLifecycle $runtime) {}

    #[Override]
    public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $failures = $this->runtime->failures();
        $requirements = array_map(fn (PackageRequirementData $requirement): array => $requirement->toArray(), FindUnmetPackageRequirementsAction::run());
        $passed = $failures === [] && $requirements === [];
        $messages = [];
        if ($failures !== []) {
            $messages[] = __('capell-core::runtime-refresh.diagnostic_failed', ['packages' => implode(', ', array_column($failures, 'package'))]);
        }

        if ($requirements !== []) {
            $messages[] = __('capell-core::package-requirements.diagnostic_failed', ['requirements' => implode(', ', array_map(
                fn (array $requirement): string => $requirement['package'] . ' -> ' . $requirement['requirement'],
                $requirements,
            ))]);
        }

        $remediation = [];
        if ($failures !== []) {
            $remediation[] = __('capell-core::runtime-refresh.diagnostic_remediation');
        }

        if ($requirements !== []) {
            $remediation[] = __('capell-core::package-requirements.diagnostic_remediation');
        }

        return new DoctorCheckResultData(
            label: __('capell-core::runtime-refresh.diagnostic_label'),
            passed: $passed,
            message: $passed ? __('capell-core::runtime-refresh.diagnostic_ok') : implode(' ', $messages),
            remediation: $passed ? null : implode(' ', $remediation),
            id: 'core.packages.installed-runtime',
            severity: DoctorCheckSeverity::Critical,
            evidence: ['failures' => $failures, 'requirements' => $requirements],
        );
    }
}
