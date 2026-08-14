<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Data\Install\InstallReadinessCheckData;
use Capell\Core\Data\Install\InstallReadinessReportData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Enums\Install\InstallReadinessOutcome;
use Capell\Core\Enums\Install\InstallReadinessStage;
use Capell\Core\Enums\Install\InstallReadinessStatus;

function installInputForReadiness(string $siteUrl = 'https://example.test'): InstallInputData
{
    return new InstallInputData(
        siteUrl: $siteUrl,
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

it('returns a stable typed readiness report for the boot stage', function (): void {
    $report = RunInstallPreflightChecksAction::make()->report(installInputForReadiness());

    expect($report)
        ->toBeInstanceOf(InstallReadinessReportData::class)
        ->and($report->stage)->toBe(InstallReadinessStage::Boot)
        ->and($report->hasBlockingFailures())->toBeFalse()
        ->and($report->toArray())
        ->toMatchArray([
            'schema_version' => '1.0',
            'stage' => 'boot',
            'ready' => true,
        ]);

    expect($report->checks)->each->toBeInstanceOf(InstallReadinessCheckData::class);

    expect($report->checks[0]->toArray())
        ->toMatchArray([
            'stage' => 'boot',
            'status' => 'passed',
            'blocking' => false,
            'outcome' => 'automated-now',
        ]);
});

it('exposes blocking checks with remediation without throwing first', function (): void {
    $report = RunInstallPreflightChecksAction::make()->report(installInputForReadiness('not-a-url'));

    expect($report->hasBlockingFailures())->toBeTrue()
        ->and($report->blockingChecks())->toHaveCount(1)
        ->and($report->blockingChecks()[0]->key)->toBe('site-url')
        ->and($report->blockingChecks()[0]->status)->toBe(InstallReadinessStatus::Blocked)
        ->and($report->blockingChecks()[0]->outcome)->toBe(InstallReadinessOutcome::Blocked)
        ->and($report->blockingChecks()[0]->remediation)->not->toBeNull();
});
