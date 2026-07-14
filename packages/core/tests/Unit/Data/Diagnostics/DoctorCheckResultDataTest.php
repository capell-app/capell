<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

it('serializes stable diagnostic identity severity and evidence independently of labels', function (): void {
    $first = new DoctorCheckResultData(
        label: 'Required schema',
        passed: false,
        message: 'A table is missing.',
        remediation: 'Run migrations.',
        id: 'core.schema.required',
        severity: DoctorCheckSeverity::Critical,
        evidence: ['missing_tables' => ['pages']],
    );
    $renamed = new DoctorCheckResultData(
        label: 'Runtime database contract',
        passed: false,
        message: 'A table is missing.',
        remediation: 'Run migrations.',
        id: 'core.schema.required',
        severity: DoctorCheckSeverity::Critical,
        evidence: ['missing_tables' => ['pages']],
    );
    $report = new DoctorReportData('failed', collect([$renamed]));

    expect($first->id)->toBe($renamed->id)
        ->and($first->severity)->toBe($renamed->severity)
        ->and($first->passed)->toBe($renamed->passed)
        ->and($renamed->toArray())->toBe([
            'id' => 'core.schema.required',
            'severity' => 'critical',
            'label' => 'Runtime database contract',
            'passed' => false,
            'message' => 'A table is missing.',
            'remediation' => 'Run migrations.',
            'evidence' => ['missing_tables' => ['pages']],
        ])
        ->and($report->passed())->toBeFalse();
});
