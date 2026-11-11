<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\RuntimeRefresh\RunRuntimeDoctorAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

function runtimeDoctorWithChecks(DoctorCheckResultData ...$checks): void
{
    $report = DoctorReportData::fromChecks(collect($checks));

    app()->instance(BuildDoctorReportAction::class, new readonly class($report)
    {
        public function __construct(private DoctorReportData $report) {}

        public function handle(bool $installSummary = false, bool $includePackageDoctors = true): DoctorReportData
        {
            return $this->report;
        }
    });
}

function runtimeDoctorCheck(string $id, bool $passed, DoctorCheckSeverity $severity): DoctorCheckResultData
{
    return new DoctorCheckResultData('Label ' . $id, $passed, 'Message ' . $id, 'Fix ' . $id, $id, $severity);
}

it('passes the runtime stage with warnings and points at the command that lists them', function (): void {
    runtimeDoctorWithChecks(
        runtimeDoctorCheck('test.clean', true, DoctorCheckSeverity::Critical),
        runtimeDoctorCheck('test.optional', false, DoctorCheckSeverity::Warning),
    );

    $stage = RunRuntimeDoctorAction::run();

    expect($stage->passed)->toBeTrue()
        ->and($stage->message)->toContain('1 warning(s)')
        ->toContain('php artisan capell:doctor --warnings');
});

it('fails the runtime stage naming only the failed checks, not the warnings', function (): void {
    runtimeDoctorWithChecks(
        runtimeDoctorCheck('test.optional', false, DoctorCheckSeverity::Warning),
        runtimeDoctorCheck('test.broken', false, DoctorCheckSeverity::Critical),
    );

    $stage = RunRuntimeDoctorAction::run();

    expect($stage->passed)->toBeFalse()
        ->and($stage->message)->toContain('Label test.broken')
        ->not->toContain('Label test.optional');
});

it('passes the runtime stage cleanly when nothing is wrong', function (): void {
    runtimeDoctorWithChecks(runtimeDoctorCheck('test.clean', true, DoctorCheckSeverity::Critical));

    $stage = RunRuntimeDoctorAction::run();

    expect($stage->passed)->toBeTrue()
        ->and($stage->message)->toBe('All Capell Doctor checks passed.');
});
