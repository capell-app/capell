<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Data\Diagnostics\DoctorWarningSummaryData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

function doctorOutcomeCheck(bool $passed, DoctorCheckSeverity $severity, array $evidence = [], string $id = 'test.check'): DoctorCheckResultData
{
    return new DoctorCheckResultData(
        label: 'Check ' . $id,
        passed: $passed,
        message: 'Message for ' . $id,
        remediation: $passed ? null : 'Fix ' . $id,
        id: $id,
        severity: $severity,
        evidence: $evidence,
    );
}

it('derives the outcome from the pass state, the severity and warning evidence', function (bool $passed, DoctorCheckSeverity $severity, array $evidence, string $expected): void {
    expect(doctorOutcomeCheck($passed, $severity, $evidence)->outcome()->value)->toBe($expected);
})->with([
    'clean pass' => [true, DoctorCheckSeverity::Critical, [], 'passed'],
    'clean info pass' => [true, DoctorCheckSeverity::Info, [], 'passed'],
    'critical failure blocks' => [false, DoctorCheckSeverity::Critical, [], 'failed'],
    'warning severity does not block' => [false, DoctorCheckSeverity::Warning, [], 'warning'],
    'info severity does not block' => [false, DoctorCheckSeverity::Info, [], 'warning'],
    'pass that reports warnings' => [true, DoctorCheckSeverity::Warning, ['warning_count' => 3], 'warning'],
    'zero warnings is clean' => [true, DoctorCheckSeverity::Warning, ['warning_count' => 0], 'passed'],
    'critical failure is not softened by warning evidence' => [false, DoctorCheckSeverity::Critical, ['warning_count' => 3], 'failed'],
]);

it('serialises the outcome next to the severity', function (): void {
    $array = doctorOutcomeCheck(false, DoctorCheckSeverity::Warning)->toArray();

    expect($array['outcome'])->toBe('warning')
        ->and($array['severity'])->toBe('warning')
        ->and($array['passed'])->toBeFalse();
});

it('fails a report only for failed checks and counts the warning checks', function (): void {
    $clean = doctorOutcomeCheck(true, DoctorCheckSeverity::Critical, id: 'test.clean');
    $warning = doctorOutcomeCheck(false, DoctorCheckSeverity::Warning, id: 'test.warning');
    $manifest = doctorOutcomeCheck(true, DoctorCheckSeverity::Warning, ['warning_count' => 203], 'test.manifest');
    $failed = doctorOutcomeCheck(false, DoctorCheckSeverity::Critical, id: 'test.failed');

    $warned = DoctorReportData::fromChecks(collect([$clean, $warning, $manifest]));
    $broken = DoctorReportData::fromChecks(collect([$clean, $warning, $failed]));
    $tidy = DoctorReportData::fromChecks(collect([$clean]));

    expect($warned->passed())->toBeTrue()
        ->and($warned->warningCount())->toBe(2)
        ->and($warned->warnings()->pluck('id')->all())->toBe(['test.warning', 'test.manifest'])
        ->and($warned->toArray()['warning_count'])->toBe(2)
        ->and($broken->passed())->toBeFalse()
        ->and($broken->warningCount())->toBe(1)
        ->and($tidy->passed())->toBeTrue()
        ->and($tidy->warningCount())->toBe(0);
});

it('names the exact command that lists the warnings', function (): void {
    $summary = new DoctorWarningSummaryData(2);

    expect($summary->hasWarnings())->toBeTrue()
        ->and($summary->command)->toBe('php artisan capell:doctor --warnings')
        ->and($summary->message())->toContain('2 checks reported warnings')
        ->toContain('php artisan capell:doctor --warnings')
        ->and(new DoctorWarningSummaryData(1)->message())->toContain('1 check reported a warning')
        ->and(new DoctorWarningSummaryData(0)->hasWarnings())->toBeFalse();
});
