<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;

final class RequiredTablesCheck extends AbstractDoctorCheck
{
    public function __construct(private readonly CapellRuntimeSchemaContract $runtimeSchema) {}

    protected function id(): string
    {
        return 'core.schema.required';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $missingTables = $this->runtimeSchema->missingTables();
        $state = ResolveCapellInstallationStateAction::run();
        $complete = $state === CapellInstallationState::Installed || ($installSummary && $missingTables === []);

        if (! $complete) {
            return new DoctorCheckResultData(
                label: 'Required tables exist',
                passed: false,
                message: $missingTables !== [] ? sprintf('Missing tables: %s.', implode(', ', $missingTables)) : 'Core lifecycle state does not record a complete installation.',
                remediation: 'Run php artisan migrate.',
                evidence: ['installation_state' => $state->value, 'missing_tables' => $missingTables, 'required_tables' => $this->runtimeSchema->requiredTables()],
            );
        }

        return new DoctorCheckResultData(
            label: 'Required tables exist',
            passed: true,
            message: $state === CapellInstallationState::Installed ? 'All required tables exist.' : 'All required tables exist; core lifecycle completion is pending the final installer step.',
            evidence: ['installation_state' => $state->value, 'missing_tables' => [], 'required_tables' => $this->runtimeSchema->requiredTables()],
        );
    }
}
