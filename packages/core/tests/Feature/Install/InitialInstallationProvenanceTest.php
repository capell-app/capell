<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\DetectInitialInstallationAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\PackageWorkflowPlanner;

beforeEach(function (): void {
    InstallRunState::completeInitialInstallation();
    CapellExtension::query()->whereIn('composer_name', ['capell-app/core', 'capell-app/admin'])->delete();
});

it('does not classify an older empty installed admin as a first installation', function (): void {
    expect(DetectInitialInstallationAction::run())->toBeTrue();
    CapellExtension::query()->create(['composer_name' => 'capell-app/admin', 'name' => 'Admin', 'status' => ExtensionStatusEnum::Enabled]);

    expect(Site::query()->exists())->toBeFalse()
        ->and(DetectInitialInstallationAction::run())->toBeFalse();
});

it('retains the captured origin through saved installer input after package metadata changes', function (): void {
    $factory = new InstallInputFactory(new PackageWorkflowPlanner);
    $input = $factory->fromResolvedConsoleInput(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
    $saved = $input->toArray();
    expect($input->freshInstall)->toBeFalse()->and($input->initialInstallation)->toBeTrue();
    CapellExtension::query()->create(['composer_name' => 'capell-app/admin', 'name' => 'Admin', 'status' => ExtensionStatusEnum::Enabled]);

    expect(DetectInitialInstallationAction::run())->toBeFalse()
        ->and(InstallInputData::from($saved)->initialInstallation)->toBeTrue();
    unset($saved['initialInstallation']);
    expect(InstallInputData::from($saved)->initialInstallation)->toBeFalse();
});

it('captures a fresh non-destructive browser install independently of the fresh-reset option', function (): void {
    $input = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test', 'language' => 'en', 'admin_user_mode' => 'create',
        'new_user_name' => 'Admin', 'new_user_email' => 'admin@example.test', 'new_user_password' => 'password123',
        'package_selection_mode' => 'custom', 'packages' => [], 'theme' => 'none',
    ]);

    expect($input->freshInstall)->toBeFalse()->and($input->initialInstallation)->toBeTrue();
});

afterEach(function (): void {
    InstallRunState::completeInitialInstallation();
});

it('retains first installation provenance across a new console invocation until successful completion', function (): void {
    expect(DetectInitialInstallationAction::run())->toBeTrue();
    InstallRunState::rememberInitialInstallation(true);
    Site::factory()->create();
    CapellExtension::query()->create(['composer_name' => 'capell-app/admin', 'name' => 'Admin', 'status' => ExtensionStatusEnum::Enabled]);
    $factory = new InstallInputFactory(new PackageWorkflowPlanner);
    $retry = $factory->fromResolvedConsoleInput(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
    expect($retry->initialInstallation)->toBeTrue();
    InstallRunState::completeInitialInstallation();
    expect(DetectInitialInstallationAction::run())->toBeFalse();
});
