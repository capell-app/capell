<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
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

        return new DoctorCheckResultData(
            label: __('capell::runtime-refresh.diagnostic_label'),
            passed: $failures === [],
            message: $failures === [] ? __('capell::runtime-refresh.diagnostic_ok') : __('capell::runtime-refresh.diagnostic_failed', ['packages' => implode(', ', array_column($failures, 'package'))]),
            remediation: $failures === [] ? null : (string) __('capell::runtime-refresh.diagnostic_remediation'),
            id: 'core.packages.installed-runtime',
            severity: DoctorCheckSeverity::Critical,
            evidence: ['failures' => $failures],
        );
    }
}
