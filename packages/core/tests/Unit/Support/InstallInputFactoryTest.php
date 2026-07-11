<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\PackageWorkflowPlanner;

it('preserves selected theme key from console input', function (): void {
    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromResolvedConsoleInput(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        selectedThemeKey: 'corporate',
    );

    expect($inputData->selectedThemeKey)->toBe('corporate');
});

it('defaults web package selection to all core packages', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/frontend');
    CapellCore::registerPackage('capell-app/marketplace');
    CapellCore::registerPackage('capell-app/blog');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'packages' => ['capell-app/blog'],
        'extra_packages' => ['capell-app/remote-extension'],
    ]);

    expect($inputData->packages)->toBe(['capell-app/admin', 'capell-app/frontend', 'capell-app/marketplace'])
        ->and($inputData->extraPackages)->toBe(['capell-app/remote-extension']);
});

it('allows custom web package selection to install no packages', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/frontend');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'package_selection_mode' => 'custom',
        'packages' => [],
        'theme' => 'none',
    ]);

    expect($inputData->packages)->toBe([])
        ->and($inputData->extraPackages)->toBe([]);
});

it('treats web-selected install-time admin as an admin install package', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'package_selection_mode' => 'custom',
        'packages' => [],
        'extra_packages' => ['capell-app/admin'],
        'theme' => 'none',
        'admin_panel_changes_mode' => 'auto',
    ]);

    expect($inputData->packages)->toBe([])
        ->and($inputData->extraPackages)->toBe(['capell-app/admin'])
        ->and($inputData->integrateAdminPanel)->toBeTrue()
        ->and($inputData->adminAddColors)->toBeTrue()
        ->and($inputData->adminAddWidgets)->toBeTrue()
        ->and($inputData->adminAddNavigation)->toBeTrue();
});

it('keeps web theme selection empty when no theme is selected', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'theme' => 'none',
    ]);

    expect($inputData->selectedThemeKey)->toBeNull()
        ->and($inputData->extraPackages)->toBe([]);
});

it('does not include package default selected optional packages in the all core web package selection', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/filamentors', defaultSelected: true);
    CapellCore::registerPackage('capell-app/blog');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'packages' => ['capell-app/blog'],
    ]);

    $customInputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'package_selection_mode' => 'custom',
        'packages' => ['capell-app/blog'],
    ]);

    expect($inputData->packages)->toBe(['capell-app/admin'])
        ->and($inputData->extraPackages)->toBe([])
        ->and($customInputData->packages)->toBe(['capell-app/blog'])
        ->and($customInputData->extraPackages)->toBe([]);
});

it('includes configured default package names in the default web package selection', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/filamentors');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
    ], defaultPackageNames: ['capell-app/filamentors']);

    expect($inputData->packages)->toBe(['capell-app/admin', 'capell-app/filamentors'])
        ->and($inputData->extraPackages)->toBe([]);
});

it('matches console demo defaults for web installer input', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/content-sections');
    CapellCore::registerPackage('capell-app/navigation');
    CapellCore::registerPackage('vendor/demo-package');
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    CapellCore::getPackage('vendor/demo-package')->requirements = ['capell-app/content-sections', 'capell-app/navigation'];

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'demo_content' => true,
        'seed_default_data' => false,
        'theme' => 'none',
    ]);

    expect($inputData->demoContent)->toBeTrue()
        ->and($inputData->seedDefaultData)->toBeTrue()
        ->and($inputData->demoLanguages)->toBe(['en'])
        ->and($inputData->demoSites)->toBe([config('app.name', 'Capell Application')])
        ->and($inputData->packages)->toContain('capell-app/content-sections')
        ->and($inputData->packages)->toContain('capell-app/navigation')
        ->and($inputData->packages)->toContain('vendor/demo-package');
});

it('keeps demo package requirements selected for fresh web installs even when already installed', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/content-sections');
    CapellCore::registerPackage('capell-app/navigation');
    CapellCore::registerPackage('vendor/demo-package');
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    CapellCore::getPackage('vendor/demo-package')->requirements = ['capell-app/content-sections', 'capell-app/navigation'];
    CapellCore::forcePackageInstalled('capell-app/content-sections');
    CapellCore::forcePackageInstalled('capell-app/navigation');

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'demo_content' => true,
        'fresh_install' => true,
        'theme' => 'none',
    ]);

    expect($inputData->packages)->toContain('capell-app/content-sections')
        ->and($inputData->packages)->toContain('capell-app/navigation')
        ->and($inputData->packages)->toContain('vendor/demo-package');
});

it('includes independent demo packages instead of requiring them through demo kit', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/frontend');
    CapellCore::registerPackage('capell-app/content-sections');
    CapellCore::registerPackage('capell-app/seo-suite');
    CapellCore::registerPackage('capell-app/demo-kit');
    CapellCore::getPackage('capell-app/demo-kit')->demo = true;
    CapellCore::getPackage('capell-app/demo-kit')->requirements = ['capell-app/admin', 'capell-app/frontend'];
    CapellCore::getPackage('capell-app/content-sections')->demo = true;
    CapellCore::getPackage('capell-app/content-sections')->requirements = ['capell-app/admin'];
    CapellCore::getPackage('capell-app/seo-suite')->demo = true;
    CapellCore::getPackage('capell-app/seo-suite')->requirements = ['capell-app/frontend'];

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'demo_content' => true,
        'theme' => 'none',
    ]);

    expect($inputData->packages)->toContain('capell-app/demo-kit')
        ->and($inputData->packages)->toContain('capell-app/content-sections')
        ->and($inputData->packages)->toContain('capell-app/seo-suite');
});

it('does not include demo packages when web demo content is disabled', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('vendor/demo-package');
    CapellCore::getPackage('vendor/demo-package')->demo = true;

    $inputData = new InstallInputFactory(new PackageWorkflowPlanner)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
        'demo_content' => false,
        'theme' => 'none',
    ]);

    expect($inputData->demoContent)->toBeFalse()
        ->and($inputData->demoLanguages)->toBeNull()
        ->and($inputData->demoSites)->toBeNull()
        ->and($inputData->packages)->not->toContain('vendor/demo-package');
});

it('supports all and custom web package selection modes', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/core');
    CapellCore::registerPackage('capell-app/admin');
    CapellCore::registerPackage('capell-app/blog');

    $factory = new InstallInputFactory(new PackageWorkflowPlanner);
    $baseInput = [
        'site_url' => 'https://example.test',
        'language' => 'en',
        'admin_user_mode' => 'create',
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.test',
        'new_user_password' => 'password123',
    ];

    $allInputData = $factory->fromWebInput(array_merge($baseInput, [
        'package_selection_mode' => 'all',
        'extra_packages' => ['capell-app/remote-extension'],
    ]));

    $customInputData = $factory->fromWebInput(array_merge($baseInput, [
        'package_selection_mode' => 'custom',
        'packages' => ['capell-app/blog'],
        'extra_packages' => ['capell-app/remote-extension'],
    ]));

    expect($allInputData->packages)->toBe(['capell-app/admin', 'capell-app/blog'])
        ->and($allInputData->extraPackages)->toBe(['capell-app/remote-extension'])
        ->and($customInputData->packages)->toBe(['capell-app/blog'])
        ->and($customInputData->extraPackages)->toBe(['capell-app/remote-extension']);
});
