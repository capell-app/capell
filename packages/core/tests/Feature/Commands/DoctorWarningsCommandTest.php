<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\Checks\ManifestContractsCheck;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

/**
 * The report is stubbed so these tests never depend on the skeleton, the
 * database or files that other install and doctor tests leave behind.
 */
function bindDoctorChecks(DoctorCheckResultData ...$checks): void
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

function doctorWarningsCheck(string $id, bool $passed, DoctorCheckSeverity $severity, array $evidence = []): DoctorCheckResultData
{
    return new DoctorCheckResultData(
        label: 'Label of ' . $id,
        passed: $passed,
        message: 'Message of ' . $id,
        remediation: $passed ? null : 'Remediation of ' . $id,
        id: $id,
        severity: $severity,
        evidence: $evidence,
    );
}

it('exits zero when only warnings are reported and names the command that lists them', function (): void {
    bindDoctorChecks(
        doctorWarningsCheck('test.clean', true, DoctorCheckSeverity::Critical),
        doctorWarningsCheck('test.optional-binary', false, DoctorCheckSeverity::Warning),
        doctorWarningsCheck('test.manifest', true, DoctorCheckSeverity::Warning, ['warning_count' => 203]),
    );

    $exitCode = Artisan::call('capell:doctor');

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('Checks passed with warnings')
        ->toContain('2 checks reported warnings')
        ->toContain('To list them, run: php artisan capell:doctor --warnings');
});

it('exits non-zero when a check fails even if warnings are also present', function (): void {
    bindDoctorChecks(
        doctorWarningsCheck('test.optional-binary', false, DoctorCheckSeverity::Warning),
        doctorWarningsCheck('test.broken', false, DoctorCheckSeverity::Critical),
    );

    artisanCommand('capell:doctor')
        ->expectsOutputToContain('One or more checks failed')
        ->assertExitCode(Command::FAILURE);
});

it('lists only the warnings and keeps the exit code of the full report', function (): void {
    bindDoctorChecks(
        doctorWarningsCheck('test.clean', true, DoctorCheckSeverity::Critical),
        doctorWarningsCheck('test.optional-binary', false, DoctorCheckSeverity::Warning),
    );

    $exitCode = Artisan::call('capell:doctor', ['--warnings' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('Label of test.optional-binary')
        ->toContain('Remediation of test.optional-binary')
        ->not->toContain('Label of test.clean')
        ->not->toContain('To list them, run');

    bindDoctorChecks(
        doctorWarningsCheck('test.optional-binary', false, DoctorCheckSeverity::Warning),
        doctorWarningsCheck('test.broken', false, DoctorCheckSeverity::Critical),
    );

    expect(Artisan::call('capell:doctor', ['--warnings' => true]))->toBe(Command::FAILURE);
});

it('says so when there are no warnings to list', function (): void {
    bindDoctorChecks(doctorWarningsCheck('test.clean', true, DoctorCheckSeverity::Critical));

    artisanCommand('capell:doctor', ['--warnings' => true])
        ->expectsOutputToContain('No checks reported warnings')
        ->assertExitCode(Command::SUCCESS);
});

it('does not mention warnings when every check is clean', function (): void {
    bindDoctorChecks(doctorWarningsCheck('test.clean', true, DoctorCheckSeverity::Critical));

    $exitCode = Artisan::call('capell:doctor');

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(Artisan::output())->toContain('All checks passed.')
        ->not->toContain('capell:doctor --warnings');
});

it('reports the outcome of every check and the warning count in the json report', function (): void {
    bindDoctorChecks(
        doctorWarningsCheck('test.clean', true, DoctorCheckSeverity::Critical),
        doctorWarningsCheck('test.optional-binary', false, DoctorCheckSeverity::Warning),
    );

    $exitCode = Artisan::call('capell:doctor', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($report['status'])->toBe('passed')
        ->and($report['warning_count'])->toBe(1)
        ->and(array_column($report['checks'], 'outcome', 'id'))->toBe([
            'test.clean' => 'passed',
            'test.optional-binary' => 'warning',
        ]);
});

it('reports manifest contract warnings as a warning and manifest contract errors as a failure', function (): void {
    bindFakeAction(AuditExtensionContractsAction::class, [
        ['severity' => 'warning'],
        ['severity' => 'warning'],
    ]);
    $warning = resolve(ManifestContractsCheck::class)->check();

    bindFakeAction(AuditExtensionContractsAction::class, [
        ['severity' => 'warning'],
        ['severity' => 'error'],
    ]);
    $failure = resolve(ManifestContractsCheck::class)->check();

    expect($warning->outcome())->toBe(DoctorCheckOutcome::Warning)
        ->and($warning->message)->toBe('2 manifest contract warning(s).')
        ->and($warning->evidence)->toBe(['warning_count' => 2])
        ->and($failure->outcome())->toBe(DoctorCheckOutcome::Failed)
        ->and($failure->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($failure->message)->toBe('1 manifest contract error(s).');
});
