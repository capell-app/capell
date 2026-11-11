<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Install\InstallStoppedStepData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;
use Capell\Core\Support\Install\InstallRunState;

final class InstallLifecycleCheck extends AbstractDoctorCheck
{
    public function __construct(private readonly CapellRuntimeSchemaContract $runtimeSchema) {}

    protected function id(): string
    {
        return 'core.install.lifecycle';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $state = ResolveCapellInstallationStateAction::run();
        $label = __('capell-core::health.install_lifecycle.label');

        if ($state === CapellInstallationState::Installed) {
            return new DoctorCheckResultData(label: $label, passed: true, message: __('capell-core::health.install_lifecycle.passed'), evidence: ['installation_state' => $state->value]);
        }

        // The install summary runs before the installer's final step, so a complete schema is as far as it can be.
        if ($installSummary && $this->runtimeSchema->missingTables() === []) {
            return new DoctorCheckResultData(label: $label, passed: true, message: __('capell-core::health.install_lifecycle.passed_install_summary'), evidence: ['installation_state' => $state->value]);
        }

        if ($state === CapellInstallationState::NotInstalled) {
            return new DoctorCheckResultData(
                label: $label,
                passed: false,
                message: __('capell-core::health.install_lifecycle.not_installed'),
                remediation: __('capell-core::health.install_lifecycle.not_installed_remediation'),
                evidence: ['installation_state' => $state->value],
            );
        }

        $stopped = InstallRunState::stoppedStep();

        if (! $stopped instanceof InstallStoppedStepData) {
            return new DoctorCheckResultData(
                label: $label,
                passed: false,
                message: __('capell-core::health.install_lifecycle.unrecorded'),
                remediation: __('capell-core::health.install_lifecycle.unrecorded_remediation'),
                evidence: ['installation_state' => $state->value, 'stopped_step' => null],
            );
        }

        return new DoctorCheckResultData(
            label: $label,
            passed: false,
            message: __('capell-core::health.install_lifecycle.stopped', ['step' => $stopped->label, 'key' => $stopped->key]),
            remediation: $stopped->remediation(),
            evidence: ['installation_state' => $state->value, 'stopped_step' => $stopped->toArray()],
        );
    }
}
