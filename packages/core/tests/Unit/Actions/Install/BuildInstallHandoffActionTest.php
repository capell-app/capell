<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallHandoffAction;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;

it('builds a stable redacted install handoff without account or telemetry requirements', function (): void {
    $input = new InstallInputData(
        siteUrl: 'https://operator:secret@example.test/?token=private#fragment',
        packages: ['capell-app/core', 'capell-app/admin'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
    $result = new InstallRunResultData(
        selectedPackages: ['capell-app/admin', 'capell-app/core'],
        completedSteps: [
            InstallPlan::STEP_RUN_MIGRATIONS_POST,
            InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
            InstallPlan::STEP_MARK_CORE_INSTALLED,
        ],
        doctorStatus: 'passed',
    );

    $handoff = BuildInstallHandoffAction::run(
        inputData: $input,
        result: $result,
        adminUrl: 'https://admin:secret@example.test/admin?signature=private',
        firstPageStatus: 'editable',
        warnings: [
            base_path('routes/web.php') . ': password=hunter2 token=private',
        ],
    );

    expect($handoff->toArray())->toBe([
        'schemaVersion' => 1,
        'status' => 'completed',
        'selectedPackages' => ['capell-app/admin', 'capell-app/core'],
        'outcomes' => [
            'migrations' => 'completed',
            'setup' => 'completed',
            'doctor' => 'passed',
        ],
        'urls' => [
            'admin' => 'https://example.test/admin',
            'public' => 'https://example.test/',
        ],
        'firstPage' => ['status' => 'editable'],
        'warnings' => ['<application>/routes/web.php: password=[REDACTED] token=[REDACTED]'],
        'nextAction' => [
            'label' => 'Create and verify your first editable public page',
            'url' => 'https://docs.capell.app/getting-started/create-your-first-page/',
        ],
        'publicImpact' => [
            'summary' => 'Capell completed the selected foundation and extension setup. Public rendering remains application-owned.',
            'accountConnection' => 'not_required',
            'telemetrySubmission' => 'not_performed',
        ],
    ])->and(json_encode($handoff->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('secret')
        ->not->toContain('hunter2')
        ->not->toContain('private')
        ->not->toContain(base_path());
});

it('fails closed for incomplete outcomes and unsupported first page states', function (): void {
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
    $result = new InstallRunResultData([], [], 'unknown');

    $handoff = BuildInstallHandoffAction::run(
        inputData: $input,
        result: $result,
        adminUrl: null,
        firstPageStatus: 'claimed-editable',
        warnings: [],
    );

    expect($handoff->status)->toBe('incomplete')
        ->and($handoff->outcomes)->toBe([
            'migrations' => 'incomplete',
            'setup' => 'incomplete',
            'doctor' => 'unknown',
        ])->and($handoff->firstPage)->toBe(['status' => 'unavailable']);
});

it('requires an asset build before the first page when frontend preparation has not been rebuilt', function (bool $rebuilt): void {
    $input = new InstallInputData(siteUrl: 'https://example.test', packages: ['capell-app/frontend'], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false);
    $steps = [InstallPlan::STEP_RUN_MIGRATIONS_POST, InstallPlan::STEP_MARK_CORE_INSTALLED];
    if ($rebuilt) {
        $steps[] = InstallPlan::STEP_REBUILD_RESOURCES;
    }

    $handoff = BuildInstallHandoffAction::run($input, new InstallRunResultData(['capell-app/frontend'], $steps, 'passed'), null, 'editable', []);
    expect($handoff->status)->toBe('completed')
        ->and($handoff->nextAction['label'])->toBe($rebuilt ? 'Create and verify your first editable public page' : 'Install and build frontend assets before opening Admin or the public page')
        ->and($handoff->warnings)->toHaveCount($rebuilt ? 0 : 1);
})->with([false, true]);

it('uses executed frontend preparation for a theme-only requested selection', function (bool $rebuilt): void {
    $selected = ['capell-app/theme-foundation'];
    $input = new InstallInputData(siteUrl: 'https://example.test', packages: $selected, languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false);
    $steps = [InstallPlan::packageAfterInstallStepKey('capell-app/frontend'), InstallPlan::STEP_RUN_MIGRATIONS_POST, InstallPlan::STEP_MARK_CORE_INSTALLED];
    if ($rebuilt) {
        $steps[] = InstallPlan::STEP_REBUILD_RESOURCES;
    }

    $handoff = BuildInstallHandoffAction::run($input, new InstallRunResultData($selected, $steps, 'passed'), null, 'editable', []);
    expect($handoff->selectedPackages)->toBe($selected)->and($handoff->schemaVersion)->toBe(1)
        ->and($handoff->warnings)->toHaveCount($rebuilt ? 0 : 1)
        ->and($handoff->nextAction['label'])->toBe($rebuilt ? 'Create and verify your first editable public page' : 'Install and build frontend assets before opening Admin or the public page');
})->with([false, true]);
