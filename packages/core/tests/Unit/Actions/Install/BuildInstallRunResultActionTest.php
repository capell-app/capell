<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallRunResultAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;

it('builds a deterministic successful result from the completed install plan', function (): void {
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['capell-app/frontend', 'capell-app/core'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['capell-app/blog', 'capell-app/frontend'],
    );

    $result = BuildInstallRunResultAction::run($input);

    expect($result->selectedPackages)->toBe([
        'capell-app/blog',
        'capell-app/core',
        'capell-app/frontend',
    ])->and($result->completedSteps)->toBe(InstallPlan::steps($input)->pluck('key')->all())
        ->and($result->doctorStatus)->toBe('passed');
});

it('retains executed dependency phases when installed requirements disappear from later graph expansion', function (): void {
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        extraPackages: ['vendor/theme'],
    );
    $completedSteps = [InstallPlan::packageInstallStepKey('vendor/dependency'), InstallPlan::packageAfterInstallStepKey('vendor/dependency'), InstallPlan::packageInstallStepKey('vendor/theme'), InstallPlan::STEP_RUN_DOCTOR_SUMMARY, InstallPlan::STEP_MARK_CORE_INSTALLED];

    expect(BuildInstallRunResultAction::run($input, $completedSteps)->completedSteps)->toBe($completedSteps);
});
