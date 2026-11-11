<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Diagnostics\ResolvePendingMigrationsAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;

final class MigrationsCheck extends AbstractDoctorCheck
{
    private const int EXAMPLE_LIMIT = 3;

    public function __construct(private readonly CapellRuntimeSchemaContract $runtimeSchema) {}

    protected function id(): string
    {
        return 'core.migrations';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $pending = ResolvePendingMigrationsAction::run();
        $missingTables = $this->runtimeSchema->missingTables();
        $label = __('capell-core::health.migrations.label');
        $evidence = ['pending_migrations' => $pending, 'missing_tables' => $missingTables, 'required_tables' => $this->runtimeSchema->requiredTables()];

        if ($pending !== []) {
            return new DoctorCheckResultData(
                label: $label,
                passed: false,
                message: __('capell-core::health.migrations.pending', ['count' => count($pending), 'examples' => implode(', ', array_slice($pending, 0, self::EXAMPLE_LIMIT))]),
                remediation: __('capell-core::health.migrations.pending_remediation'),
                evidence: $evidence,
            );
        }

        if ($missingTables !== []) {
            return new DoctorCheckResultData(
                label: $label,
                passed: false,
                message: __('capell-core::health.migrations.missing_tables', ['tables' => implode(', ', $missingTables)]),
                remediation: __('capell-core::health.migrations.missing_tables_remediation'),
                evidence: $evidence,
            );
        }

        return new DoctorCheckResultData(label: $label, passed: true, message: __('capell-core::health.migrations.passed'), evidence: $evidence);
    }
}
