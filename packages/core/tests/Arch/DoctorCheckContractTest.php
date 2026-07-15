<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Actions\Diagnostics\BuildThemeDoctorReportAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

it('keeps every core and theme doctor result on the stable native contract', function (): void {
    $reports = [
        BuildDoctorReportAction::run(includePackageDoctors: false),
        BuildThemeDoctorReportAction::run(
            theme: 'missing-theme-for-contract-test',
            path: storage_path('framework/testing/missing-theme-for-contract-test'),
        ),
    ];

    foreach ($reports as $report) {
        expect($report)->toBeInstanceOf(DoctorReportData::class);
        $ids = $report->checks->map(fn (DoctorCheckResultData $check): string => $check->id);

        expect($ids->unique()->count())->toBe($ids->count());

        $report->checks->each(function (DoctorCheckResultData $check): void {
            expect($check->id)->toMatch('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/')
                ->and($check->severity)->toBeInstanceOf(DoctorCheckSeverity::class)
                ->and($check->toArray())->toHaveKeys([
                    'id',
                    'severity',
                    'label',
                    'passed',
                    'message',
                    'remediation',
                    'evidence',
                ]);

            if ($check->isCriticalFailure()) {
                expect($check->remediation)->toBeString()->not->toBeEmpty();
            }
        });

        expect(json_decode(json_encode($report->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
            ->toBe($report->toArray());
    }
});
