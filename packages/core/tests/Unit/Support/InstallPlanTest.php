<?php

declare(strict_types=1);

use Capell\Core\Data\Install\InstallStepData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPlan;

class InstallPlanSetupActionOnlyPackageAction {}

function makePlanInput(array $overrides = []): InstallInputData
{
    return new InstallInputData(
        siteUrl: $overrides['siteUrl'] ?? 'https://example.com',
        packages: $overrides['packages'] ?? [],
        languages: $overrides['languages'] ?? ['en'],
        demoContent: $overrides['demoContent'] ?? false,
        cachesToClear: $overrides['cachesToClear'] ?? [],
        generateSitemap: $overrides['generateSitemap'] ?? false,
        generateStaticSite: $overrides['generateStaticSite'] ?? false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
        seedDefaultData: $overrides['seedDefaultData'] ?? false,
        installFilamentPanel: $overrides['installFilamentPanel'] ?? false,
        extraPackages: $overrides['extraPackages'] ?? [],
        integrateAdminPanel: $overrides['integrateAdminPanel'] ?? false,
        rebuildResources: $overrides['rebuildResources'] ?? false,
        freshInstall: $overrides['freshInstall'] ?? false,
        installWelcomeRoute: $overrides['installWelcomeRoute'] ?? false,
        installDeveloperTooling: $overrides['installDeveloperTooling'] ?? false,
        configureBoostDeveloperTooling: $overrides['configureBoostDeveloperTooling'] ?? false,
        selectedThemeKey: $overrides['selectedThemeKey'] ?? null,
    );
}

it('builds a minimal plan when no optional toggles are enabled', function (): void {
    $plan = InstallPlan::build(makePlanInput());
    $stepKeys = array_column($plan, 'key');

    expect($stepKeys)->toContain(
        InstallPlan::STEP_PREFLIGHT_CHECKS,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
        InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS,
        InstallPlan::STEP_RESOLVE_USER,
        InstallPlan::STEP_CLEAR_CACHES,
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        InstallPlan::STEP_MARK_CORE_INSTALLED,
    )->and($stepKeys)->not->toContain(
        InstallPlan::STEP_GENERATE_SITEMAP,
        InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES,
        InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING,
    );
});

it('includes sitemap and extra package steps when toggled', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'demoContent' => true,
        'generateSitemap' => true,
        'extraPackages' => ['vendor/some-extra'],
    ]));

    $keys = array_column($plan, 'key');

    expect($keys)->toContain(
        InstallPlan::STEP_GENERATE_SITEMAP,
        InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES,
        InstallPlan::STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS,
    );

    expect($keys)->not->toContain(InstallPlan::STEP_INSTALL_FILAMENT_PANEL);
});

it('splits non-core package installation into per-package browser install steps', function (): void {
    CapellCore::registerPackage(name: 'capell-app/admin');
    CapellCore::getPackage('capell-app/admin')->sort = 10;
    CapellCore::registerPackage(
        name: 'capell-app/blog',
        setupCommand: 'capell:blog-setup',
    );
    CapellCore::getPackage('capell-app/blog')->sort = 20;
    CapellCore::getPackage('capell-app/blog')->demoCommand = 'capell:blog-demo';
    CapellCore::getPackage('capell-app/blog')->afterInstallCommand = 'capell:blog-after-install';

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/admin', 'capell-app/blog'],
        'seedDefaultData' => true,
        'demoContent' => true,
    ]));

    $stepKeys = array_column($plan, 'key');

    expect($stepKeys)->toContain(
        InstallPlan::packageInstallStepKey('capell-app/blog'),
        InstallPlan::packageSetupStepKey('capell-app/blog'),
        InstallPlan::packageDemoStepKey('capell-app/blog'),
        InstallPlan::packageAfterInstallStepKey('capell-app/blog'),
    )
        ->and($stepKeys)->not->toContain(InstallPlan::packageInstallStepKey('capell-app/admin'))
        ->and($stepKeys)->not->toContain(InstallPlan::STEP_INSTALL_PACKAGES)
        ->and(array_search(InstallPlan::packageInstallStepKey('capell-app/blog'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageSetupStepKey('capell-app/blog'), $stepKeys, true))
        ->and(array_search(InstallPlan::packageSetupStepKey('capell-app/blog'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageDemoStepKey('capell-app/blog'), $stepKeys, true))
        ->and(array_search(InstallPlan::packageDemoStepKey('capell-app/blog'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageAfterInstallStepKey('capell-app/blog'), $stepKeys, true));
});

it('includes setup steps for selected core packages with setup commands', function (): void {
    CapellCore::registerPackage(
        name: 'capell-app/admin',
        setupCommand: 'capell:admin-setup',
    );

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/admin'],
        'seedDefaultData' => true,
    ]));

    $stepKeys = array_column($plan, 'key');

    expect($stepKeys)->toContain(InstallPlan::packageSetupStepKey('capell-app/admin'))
        ->and($stepKeys)->not->toContain(InstallPlan::packageInstallStepKey('capell-app/admin'));
});

it('includes setup steps for packages with setup lifecycle actions', function (): void {
    CapellCore::registerPackage(name: 'capell-app/action-only');
    CapellCore::getPackage('capell-app/action-only')->setupAction = InstallPlanSetupActionOnlyPackageAction::class;

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/action-only'],
        'seedDefaultData' => true,
    ]));

    expect(array_column($plan, 'key'))->toContain(InstallPlan::packageSetupStepKey('capell-app/action-only'));
});

it('uses demo instead of matching setup commands when demo content is enabled', function (): void {
    CapellCore::registerPackage(name: 'capell-app/demo-kit');
    CapellCore::getPackage('capell-app/demo-kit')->setupCommand = 'capell:demo-kit-full-demo';
    CapellCore::getPackage('capell-app/demo-kit')->demoCommand = 'capell:demo-kit-full-demo';

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/demo-kit'],
        'seedDefaultData' => true,
        'demoContent' => true,
    ]));

    $stepKeys = array_column($plan, 'key');

    expect($stepKeys)->toContain(InstallPlan::packageDemoStepKey('capell-app/demo-kit'))
        ->and($stepKeys)->not->toContain(InstallPlan::packageSetupStepKey('capell-app/demo-kit'));
});

it('does not include demo package steps unless demo content is enabled', function (): void {
    CapellCore::registerPackage(name: 'capell-app/blog');
    CapellCore::getPackage('capell-app/blog')->demoCommand = 'capell:blog-demo';

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/blog'],
        'demoContent' => false,
    ]));

    expect(array_column($plan, 'key'))->not->toContain(InstallPlan::packageDemoStepKey('capell-app/blog'));
});

it('only includes the selected theme demo step while keeping non-theme demo steps', function (): void {
    CapellCore::registerPackage(name: 'capell-app/foundation-theme', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/foundation-theme')->themeKey = 'foundation';
    CapellCore::getPackage('capell-app/foundation-theme')->demoCommand = 'capell:foundation-theme-demo';

    CapellCore::registerPackage(name: 'capell-app/theme-agency', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-agency')->themeKey = 'agency';
    CapellCore::getPackage('capell-app/theme-agency')->demoCommand = 'capell:theme-agency-demo';

    CapellCore::registerPackage(name: 'capell-app/blog');
    CapellCore::getPackage('capell-app/blog')->demoCommand = 'capell:blog-demo';

    $plan = InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/foundation-theme', 'capell-app/theme-agency', 'capell-app/blog'],
        'demoContent' => true,
        'selectedThemeKey' => 'foundation',
    ]));

    expect(array_column($plan, 'key'))->toContain(
        InstallPlan::packageDemoStepKey('capell-app/foundation-theme'),
        InstallPlan::packageDemoStepKey('capell-app/blog'),
    )->not->toContain(InstallPlan::packageDemoStepKey('capell-app/theme-agency'));
});

it('includes the developer tooling step when requested', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'installDeveloperTooling' => true,
    ]));

    expect(array_column($plan, 'key'))->toContain(InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING);
});

it('runs developer tooling after extra package require and before extra vendor migrations', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'extraPackages' => ['vendor/some-extra'],
        'installDeveloperTooling' => true,
    ]));

    expect(array_column($plan, 'key'))->toBe([
        InstallPlan::STEP_PREFLIGHT_CHECKS,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
        InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS,
        InstallPlan::STEP_PUBLISH_CAPELL_MIGRATIONS,
        InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS,
        InstallPlan::STEP_RUN_MIGRATIONS_PRE,
        InstallPlan::STEP_PUBLISH_CAPELL_SETTINGS_MIGRATIONS,
        InstallPlan::STEP_RUN_MIGRATIONS_MID,
        InstallPlan::STEP_RESOLVE_USER,
        InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES,
        InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING,
        InstallPlan::STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS,
        InstallPlan::STEP_INSTALL_PACKAGES,
        InstallPlan::STEP_RUN_MIGRATIONS_POST,
        InstallPlan::STEP_CLEAR_CACHES,
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        InstallPlan::STEP_MARK_CORE_INSTALLED,
    ]);
});

it('locks the release install order through package setup, demo, resources, and doctor summary', function (): void {
    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        setupCommand: 'capell:frontend-setup',
    );
    CapellCore::getPackage('capell-app/frontend')->sort = 10;
    CapellCore::registerPackage(
        name: 'capell-app/foundation-theme',
        setupCommand: 'capell:foundation-theme-setup',
    );
    CapellCore::getPackage('capell-app/foundation-theme')->sort = 20;
    CapellCore::getPackage('capell-app/foundation-theme')->demoCommand = 'capell:foundation-theme-demo';
    CapellCore::getPackage('capell-app/foundation-theme')->afterInstallCommand = 'capell:foundation-theme-after';

    $stepKeys = array_column(InstallPlan::build(makePlanInput([
        'packages' => ['capell-app/frontend', 'capell-app/foundation-theme'],
        'seedDefaultData' => true,
        'demoContent' => true,
        'rebuildResources' => true,
        'selectedThemeKey' => 'foundation',
    ])), 'key');

    expect(array_search(InstallPlan::packageInstallStepKey('capell-app/foundation-theme'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageSetupStepKey('capell-app/frontend'), $stepKeys, true))
        ->and(array_search(InstallPlan::packageSetupStepKey('capell-app/frontend'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageDemoStepKey('capell-app/foundation-theme'), $stepKeys, true))
        ->and(array_search(InstallPlan::packageDemoStepKey('capell-app/foundation-theme'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::packageAfterInstallStepKey('capell-app/foundation-theme'), $stepKeys, true))
        ->and(array_search(InstallPlan::packageAfterInstallStepKey('capell-app/foundation-theme'), $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_REBUILD_RESOURCES, $stepKeys, true))
        ->and(array_search(InstallPlan::STEP_REBUILD_RESOURCES, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_RUN_DOCTOR_SUMMARY, $stepKeys, true));
});

it('does not install a filament panel just because extra packages are selected', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'extraPackages' => ['vendor/some-extra'],
        'installFilamentPanel' => true,
    ]));

    expect(array_column($plan, 'key'))->not->toContain(InstallPlan::STEP_INSTALL_FILAMENT_PANEL);
});

it('requires admin before installing filament and integrating the admin panel when admin is an extra package', function (): void {
    $stepKeys = array_column(InstallPlan::build(makePlanInput([
        'extraPackages' => ['capell-app/admin'],
        'integrateAdminPanel' => true,
    ])), 'key');

    expect(array_search(InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_INSTALL_FILAMENT_PANEL, $stepKeys, true))
        ->and(array_search(InstallPlan::STEP_INSTALL_FILAMENT_PANEL, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_INSTALL_PACKAGES, $stepKeys, true))
        ->and(array_search(InstallPlan::STEP_INSTALL_PACKAGES, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_INTEGRATE_ADMIN_PANEL, $stepKeys, true));
});

it('publishes package migrations before the first migration run', function (): void {
    $plan = InstallPlan::build(new InstallInputData(
        siteUrl: 'https://example.com',
        packages: ['capell-app/publishing-studio'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    ));

    $stepKeys = array_column($plan, 'key');

    expect(array_search(InstallPlan::STEP_PUBLISH_CAPELL_MIGRATIONS, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS, $stepKeys, true));
    expect(array_search(InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_RUN_MIGRATIONS_PRE, $stepKeys, true));
});

it('includes the filament panel check when admin integration is requested', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'integrateAdminPanel' => true,
        'packages' => ['capell-app/admin'],
    ]));

    expect(array_column($plan, 'key'))->toContain(InstallPlan::STEP_INSTALL_FILAMENT_PANEL);
});

it('publishes vendor migrations again after requiring extra packages', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'extraPackages' => ['vendor/some-extra'],
    ]));

    expect(array_column($plan, 'key'))->toBe([
        InstallPlan::STEP_PREFLIGHT_CHECKS,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
        InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS,
        InstallPlan::STEP_PUBLISH_CAPELL_MIGRATIONS,
        InstallPlan::STEP_PUBLISH_PACKAGE_MIGRATIONS,
        InstallPlan::STEP_RUN_MIGRATIONS_PRE,
        InstallPlan::STEP_PUBLISH_CAPELL_SETTINGS_MIGRATIONS,
        InstallPlan::STEP_RUN_MIGRATIONS_MID,
        InstallPlan::STEP_RESOLVE_USER,
        InstallPlan::STEP_REQUIRE_EXTRA_PACKAGES,
        InstallPlan::STEP_PUBLISH_EXTRA_VENDOR_MIGRATIONS,
        InstallPlan::STEP_INSTALL_PACKAGES,
        InstallPlan::STEP_RUN_MIGRATIONS_POST,
        InstallPlan::STEP_CLEAR_CACHES,
        InstallPlan::STEP_RUN_DOCTOR_SUMMARY,
        InstallPlan::STEP_MARK_CORE_INSTALLED,
    ]);
});

it('clears existing capell data after preflight when fresh install is enabled', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'freshInstall' => true,
    ]));

    expect(array_slice(array_column($plan, 'key'), 0, 3))->toBe([
        InstallPlan::STEP_PREFLIGHT_CHECKS,
        InstallPlan::STEP_PREPARE_FRESH_INSTALL,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    ]);
});

it('includes npm rebuild resources step when toggled', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'rebuildResources' => true,
    ]));

    expect(array_column($plan, 'key'))->toContain(InstallPlan::STEP_REBUILD_RESOURCES);
});

it('includes welcome route step when toggled', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'installWelcomeRoute' => true,
    ]));

    expect(array_column($plan, 'key'))->toContain(InstallPlan::STEP_INSTALL_WELCOME_ROUTE);
});

it('removes the home route before rebuilding resources', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'installWelcomeRoute' => true,
        'rebuildResources' => true,
    ]));

    $stepKeys = array_column($plan, 'key');

    expect(array_search(InstallPlan::STEP_INSTALL_WELCOME_ROUTE, $stepKeys, true))
        ->toBeLessThan(array_search(InstallPlan::STEP_REBUILD_RESOURCES, $stepKeys, true));
});

it('exposes typed step descriptors while preserving the array plan shape', function (): void {
    $steps = InstallPlan::steps(makePlanInput([
        'demoContent' => true,
        'packages' => ['capell-app/admin'],
    ]));

    expect($steps->first())->toBeInstanceOf(InstallStepData::class)
        ->and($steps->map->toPlanArray()->all())->toBe(InstallPlan::build(makePlanInput([
            'demoContent' => true,
            'packages' => ['capell-app/admin'],
        ])));
});

it('skips admin panel integration while installing seed default data', function (): void {
    $plan = InstallPlan::build(makePlanInput([
        'integrateAdminPanel' => true,
        'packages' => ['capell-app/admin'],
        'seedDefaultData' => true,
    ]));

    expect(array_column($plan, 'key'))->not->toContain(InstallPlan::STEP_INTEGRATE_ADMIN_PANEL);
});

it('returns the next step key in order', function (): void {
    $plan = InstallPlan::build(makePlanInput());

    $next = InstallPlan::findNextStep($plan, InstallPlan::STEP_PREFLIGHT_CHECKS);

    expect($next)->toBe(InstallPlan::STEP_PREPARE_ENVIRONMENT);
});

it('returns null when there is no next step', function (): void {
    $plan = InstallPlan::build(makePlanInput());

    $next = InstallPlan::findNextStep($plan, InstallPlan::STEP_MARK_CORE_INSTALLED);

    expect($next)->toBeNull();
});

it('looks up the human label for a step', function (): void {
    $plan = InstallPlan::build(makePlanInput());

    expect(InstallPlan::labelForStep($plan, InstallPlan::STEP_PREFLIGHT_CHECKS))
        ->toBe('Run preflight checks');
});
