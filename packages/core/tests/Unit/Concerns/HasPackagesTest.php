<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

it('registerPackage does not accept manifest-backed params', function (): void {
    $reflection = new ReflectionMethod(CapellCore::getFacadeRoot(), 'registerPackage');
    $paramNames = array_map(fn (ReflectionParameter $param): string => $param->getName(), $reflection->getParameters());

    expect($paramNames)->not->toContain('icon')
        ->and($paramNames)->not->toContain('url')
        ->and($paramNames)->not->toContain('scopes')
        ->and($paramNames)->not->toContain('demoCommand')
        ->and($paramNames)->not->toContain('requirements')
        ->and($paramNames)->not->toContain('sort')
        ->and($paramNames)->not->toContain('shortName')
        ->and($paramNames)->not->toContain('afterInstallCommand')
        ->and($paramNames)->not->toContain('afterInstallParams')
        ->and($paramNames)->not->toContain('upgradeCommand')
        ->and($paramNames)->not->toContain('demoParams');
});

it('registerPackage resolves closure description lazily', function (): void {
    CapellCore::registerPackage('vendor/lazy-desc', description: fn (): string => 'translated description');

    expect(CapellCore::getPackage('vendor/lazy-desc')->getDescription())->toBe('translated description');
});

it('treats discovered composer packages as available without enabling them', function (): void {
    $composerName = 'capell-app/blog-characterization-' . bin2hex(random_bytes(4));
    $packagePath = makeComposerPackageFixture($composerName);

    CapellCore::registerPackage(
        name: $composerName,
        path: $packagePath,
        version: '2.0.0',
    );

    expect(CapellCore::isPackageAvailable($composerName))->toBeTrue()
        ->and(CapellCore::isPackageEnabled($composerName))->toBeFalse()
        ->and(CapellCore::isPackageInstalled($composerName))->toBeFalse();
});

it('does not treat a composer path as installed when composer json names another package', function (): void {
    $composerName = 'capell-app/blog-characterization-' . bin2hex(random_bytes(4));
    $packagePath = makeComposerPackageFixture('capell-app/different-package-' . bin2hex(random_bytes(4)));

    CapellCore::registerPackage(
        name: $composerName,
        path: $packagePath,
        version: '2.0.0',
    );

    expect(CapellCore::isPackageInstalled($composerName))->toBeFalse();
});

it('treats trusted core packages as installed when they are composer available', function (): void {
    CapellCore::clearPackages();

    $packagePath = makeComposerPackageFixture('capell-app/admin');

    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: $packagePath,
    );

    expect(CapellCore::getPackage('capell-app/admin')->isCore())->toBeTrue()
        ->and(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::canUninstallPackage('capell-app/admin'))->toBeTrue();
});

it('does not let forced install state disable composer-available core packages', function (): void {
    $packagePath = makeComposerPackageFixture('capell-app/admin');

    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: $packagePath,
    );
    CapellCore::forcePackageInstalled('capell-app/admin', false);

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::getPackage('capell-app/admin')->isInstalled())->toBeTrue();
});

it('does not treat trusted core packages as installed when composer availability cannot be proven', function (): void {
    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return false;
        }
    });

    CapellCore::registerPackage(
        name: 'capell-app/admin',
    );

    expect(CapellCore::getPackage('capell-app/admin')->isCore())->toBeTrue()
        ->and(CapellCore::isPackageAvailable('capell-app/admin'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('capell-app/admin'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeFalse();
});

it('treats the umbrella core package name as installed when it is composer available', function (): void {
    CapellCore::clearPackages();

    $packagePath = makeComposerPackageFixture('capell-app/capell');

    CapellCore::registerPackage(
        name: 'capell-app/capell',
        path: $packagePath,
    );

    expect(CapellCore::getPackage('capell-app/capell')->isCore())->toBeTrue()
        ->and(CapellCore::isPackageInstalled('capell-app/capell'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('capell-app/capell'))->toBeTrue()
        ->and(CapellCore::canUninstallPackage('capell-app/capell'))->toBeTrue();
});

it('treats the umbrella core package name as installed when the core composer package is available', function (): void {
    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return $composerName === 'capell-app/core';
        }
    });

    CapellCore::registerPackage(
        name: 'capell-app/capell',
    );

    expect(CapellCore::getPackage('capell-app/capell')->isCore())->toBeTrue()
        ->and(CapellCore::isPackageInstalled('capell-app/capell'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('capell-app/capell'))->toBeTrue();
});

it('does not trust core declarations from non-core package names', function (): void {
    $packageName = 'vendor/spoofed-core-' . bin2hex(random_bytes(4));
    $packagePath = makeCoreManifestComposerPackageFixture($packageName);

    CapellCore::registerPackage(
        name: $packageName,
        path: $packagePath,
    );

    expect(CapellCore::getPackage($packageName)->isCore())->toBeFalse()
        ->and(CapellCore::isPackageAvailable($packageName))->toBeTrue()
        ->and(CapellCore::isPackageInstalled($packageName))->toBeFalse()
        ->and(CapellCore::isPackageEnabled($packageName))->toBeFalse();
});

it('does not trust core declarations from registered manifest packages', function (): void {
    $packageName = 'vendor/spoofed-manifest-core-' . bin2hex(random_bytes(4));

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: $packageName,
        surfaces: ['admin'],
    )));

    expect(CapellCore::getPackage($packageName)->isCore())->toBeFalse()
        ->and(CapellCore::isPackageInstalled($packageName))->toBeFalse()
        ->and(CapellCore::isPackageEnabled($packageName))->toBeFalse();
});

it('does not trust core declarations passed directly into package data', function (): void {
    $package = new PackageData(
        name: 'vendor/spoofed-package-data-core',
        type: PackageTypeEnum::Plugin,
        core: true,
    );

    expect($package->isCore())->toBeFalse();
});

it('registerPackage accepts plain string description', function (): void {
    CapellCore::registerPackage('vendor/static-desc', description: 'plain description');

    expect(CapellCore::getPackage('vendor/static-desc')->getDescription())->toBe('plain description');
});

it('registerPackage accepts the expected params', function (): void {
    $reflection = new ReflectionMethod(CapellCore::getFacadeRoot(), 'registerPackage');
    $paramNames = array_map(fn (ReflectionParameter $param): string => $param->getName(), $reflection->getParameters());

    expect($paramNames)->toContain('name')
        ->and($paramNames)->toContain('type')
        ->and($paramNames)->toContain('serviceProviderClass')
        ->and($paramNames)->toContain('path')
        ->and($paramNames)->toContain('version')
        ->and($paramNames)->toContain('setting')
        ->and($paramNames)->toContain('permissions')
        ->and($paramNames)->toContain('description')
        ->and($paramNames)->toContain('installCommand')
        ->and($paramNames)->toContain('installParams')
        ->and($paramNames)->toContain('setupCommand')
        ->and($paramNames)->toContain('setupParams')
        ->and($paramNames)->toContain('defaultSelected');
});

it('registerPackage can mark optional packages as default selected', function (): void {
    CapellCore::registerPackage('capell-app/filamentors', defaultSelected: true);

    expect(CapellCore::getPackage('capell-app/filamentors')->defaultSelected)->toBeTrue()
        ->and(CapellCore::getPackage('capell-app/filamentors')->isCore())->toBeFalse();
});

it('registerManifestPackage registers provider-only packages for installer selection', function (): void {
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/provider-only-theme',
        surfaces: ['frontend'],
        providers: [
            'runtime' => ['Vendor\\Theme\\ThemeServiceProvider'],
        ],
        overrides: [
            'kind' => 'theme',
            'dependencies' => [
                'requires' => ['vendor/theme-core'],
                'supports' => [],
                'conflicts' => [],
            ],
            'product' => ['group' => 'Capell Themes', 'tier' => 'premium', 'bundle' => 'themes'],
            'description' => 'Provider-only theme package.',
            'themeKey' => 'provider-only',
            'defaultSelected' => true,
            'scopes' => ['frontend'],
        ],
    )));

    $package = CapellCore::getPackage('vendor/provider-only-theme');

    expect($package->name)->toBe('vendor/provider-only-theme')
        ->and($package->type)->toBe(PackageTypeEnum::Theme)
        ->and($package->hasFrontendScope())->toBeTrue()
        ->and($package->getRequirements())->toBe(['vendor/theme-core'])
        ->and($package->getProductGroup())->toBe('Capell Themes')
        ->and($package->getTier())->toBe('premium')
        ->and($package->getBundle())->toBe('themes')
        ->and($package->getDescription())->toBe('Provider-only theme package.')
        ->and($package->defaultSelected)->toBeTrue();
});

it('registerManifestPackage exposes demo package metadata', function (): void {
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/demo-package',
        overrides: [
            'demo' => true,
        ],
    )));

    expect(CapellCore::getPackage('vendor/demo-package')->isDemo())->toBeTrue();
});

it('packages with demo commands are demo packages', function (): void {
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/demo-command-package',
        overrides: [
            'commands' => [
                'install' => null,
                'setup' => null,
                'demo' => 'vendor:demo-command',
                'doctor' => null,
            ],
        ],
    )));

    expect(CapellCore::getPackage('vendor/demo-command-package')->isDemo())->toBeTrue();
});

it('registerManifestPackage exposes package lifecycle commands from capell manifests', function (): void {
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/lifecycle-theme',
        surfaces: ['frontend'],
        overrides: [
            'kind' => 'theme',
            'commands' => [
                'install' => 'capell:lifecycle-install',
                'installParams' => ['--seed'],
                'setup' => 'capell:lifecycle-setup',
                'setupParams' => ['--force'],
                'demo' => 'capell:lifecycle-demo',
                'demoParams' => ['--site=demo'],
                'afterInstall' => 'capell:lifecycle-after-install',
                'afterInstallParams' => ['--assets'],
            ],
            'actions' => [
                'install' => LifecycleRecorderAction::class,
                'setup' => LifecycleRecorderAction::class,
                'afterInstall' => LifecycleRecorderAction::class,
            ],
        ],
    )));

    $package = CapellCore::getPackage('vendor/lifecycle-theme');

    expect($package->getInstallCommand())->toBe('capell:lifecycle-install')
        ->and($package->getInstallParams())->toBe(['--seed'])
        ->and($package->getSetupCommand())->toBe('capell:lifecycle-setup')
        ->and($package->getSetupParams())->toBe(['--force'])
        ->and($package->getDemoCommand())->toBe('capell:lifecycle-demo')
        ->and($package->getDemoParams())->toBe(['--site=demo'])
        ->and($package->getAfterInstallCommand())->toBe('capell:lifecycle-after-install')
        ->and($package->getAfterInstallParams())->toBe(['--assets'])
        ->and($package->getInstallAction())->toBe(LifecycleRecorderAction::class)
        ->and($package->getSetupAction())->toBe(LifecycleRecorderAction::class)
        ->and($package->getAfterInstallAction())->toBe(LifecycleRecorderAction::class);
});

it('packages expose product grouping metadata from capell manifests', function (): void {
    CapellCore::clearPackages();

    $formBuilderPackagePath = makePackageManifestFixture([
        'name' => 'vendor/form-builder',
        'product' => ['group' => 'Capell FormBuilder', 'tier' => 'premium', 'bundle' => 'form-builder'],
    ]);

    $blogPackagePath = makePackageManifestFixture([
        'name' => 'vendor/blog',
        'product' => ['group' => 'Capell Foundation', 'tier' => 'free', 'bundle' => 'foundation'],
    ]);

    CapellCore::registerPackage('vendor/form-builder', path: $formBuilderPackagePath);
    CapellCore::registerPackage('vendor/blog', path: $blogPackagePath);

    $groups = CapellCore::getPackagesGroupedByProductGroup();
    $formBuilderGroup = expectPresent($groups->get('Capell FormBuilder'));

    expect(CapellCore::getPackage('vendor/form-builder')->getProductGroup())->toBe('Capell FormBuilder')
        ->and(CapellCore::getPackage('vendor/form-builder')->getTier())->toBe('premium')
        ->and(CapellCore::getPackage('vendor/form-builder')->getBundle())->toBe('form-builder')
        ->and($groups->keys()->all())->toContain('Capell FormBuilder', 'Capell Foundation')
        ->and($formBuilderGroup->pluck('name')->all())->toBe(['vendor/form-builder'])
        ->and(CapellCore::getPackagesGroupedByProductGroup(tier: 'premium')->keys()->all())->toBe(['Capell FormBuilder']);
});

it('memoizes package ordering for all filtering and sorting parameter combinations', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('capell-app/capell');
    CapellCore::registerPackage('vendor/first');
    CapellCore::registerPackage('vendor/second');

    $planner = new class
    {
        public int $sorts = 0;

        /** @param Collection<string, PackageData> $packages */
        public function order(Collection $packages): Collection
        {
            $this->sorts++;

            return $packages->sortKeys();
        }
    };

    app()->instance(PackageWorkflowPlanner::class, $planner);

    $withoutCoreBySort = CapellCore::getPackages();
    $withoutCoreByDependencies = CapellCore::getPackages(sortByDependencies: true);
    $withCoreBySort = CapellCore::getPackages(withoutCore: false);
    $withCoreByDependencies = CapellCore::getPackages(withoutCore: false, sortByDependencies: true);

    expect($withoutCoreBySort->keys()->all())->not->toContain('capell-app/capell')
        ->and($withoutCoreByDependencies->keys()->all())->not->toContain('capell-app/capell')
        ->and($withCoreBySort->keys()->all())->toContain('capell-app/capell')
        ->and($withCoreByDependencies->keys()->all())->toContain('capell-app/capell');

    $withoutCoreBySort->forget('vendor/first');
    $withoutCoreByDependencies->forget('vendor/second');

    expect($planner->sorts)->toBe(2)
        ->and(CapellCore::getPackages()->keys()->all())->toContain('vendor/first', 'vendor/second')
        ->and(CapellCore::getPackages(sortByDependencies: true)->keys()->all())->toContain('vendor/first', 'vendor/second')
        ->and(CapellCore::getPackages())->not->toBe($withoutCoreBySort)
        ->and(CapellCore::getPackages(sortByDependencies: true))->not->toBe($withoutCoreByDependencies)
        ->and($planner->sorts)->toBe(2);
});

it('invalidates memoized package ordering when forcing an unknown package state', function (): void {
    CapellCore::clearPackages();

    CapellCore::getPackages();

    CapellCore::forcePackageInstalled('vendor/forced-package');

    expect(CapellCore::getPackages()->keys()->all())->toBe(['vendor/forced-package']);
});

it('invalidates memoized package collections when package state changes', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/initial');

    CapellCore::getPackages();

    CapellCore::registerPackage('vendor/runtime-package');

    expect(CapellCore::getPackages()->keys()->all())->toContain('vendor/runtime-package');

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/manifest-package',
    )));

    $afterManifestRegistration = CapellCore::getPackages();

    expect($afterManifestRegistration->keys()->all())->toContain('vendor/manifest-package');

    CapellCore::clearExtensionCache();

    $afterExtensionCacheClear = CapellCore::getPackages();

    expect($afterExtensionCacheClear->keys()->all())->toBe($afterManifestRegistration->keys()->all());

    CapellCore::clearPackages();

    expect(CapellCore::getPackages())->toBeEmpty();
});

it('memoizes normalized uninstalled extension names until extension caches are cleared', function (): void {
    CapellCore::clearPackages();
    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, collect([
        'vendor/first',
        '',
        123,
    ]), ttl: 0);

    $registry = resolve(CapellPackageRegistry::class);
    $getUninstalledExtensionNames = new ReflectionMethod($registry, 'getUninstalledExtensionNames');

    expect($getUninstalledExtensionNames->invoke($registry))->toBe(['vendor/first']);

    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, ['vendor/second'], ttl: 0);

    expect($getUninstalledExtensionNames->invoke($registry))->toBe(['vendor/first']);

    CapellCore::clearExtensionCache();

    expect($getUninstalledExtensionNames->invoke($registry))->toBe(['vendor/second']);
});

it('invalidates memoized uninstalled extension names when packages mutate or are cleared', function (): void {
    CapellCore::clearPackages();
    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, ['vendor/first'], ttl: 0);
    CapellCore::registerPackage('vendor/first');
    CapellCore::forcePackageInstalled('vendor/first');

    expect(CapellCore::isPackageInstalled('vendor/first'))->toBeFalse();

    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, [], ttl: 0);
    CapellCore::registerPackage('vendor/first');

    expect(CapellCore::isPackageInstalled('vendor/first'))->toBeTrue();

    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, ['vendor/first'], ttl: 0);
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/second',
    )));

    expect(CapellCore::isPackageInstalled('vendor/first'))->toBeFalse();

    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, ['vendor/first'], ttl: 0);
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/first');
    CapellCore::forcePackageInstalled('vendor/first');

    expect(CapellCore::isPackageInstalled('vendor/first'))->toBeFalse();
});

it('does not enable a paid marketplace package when its runtime gate is blocked', function (): void {
    $composerName = 'capell-app/blocked-paid-' . bin2hex(random_bytes(4));

    CapellCore::registerPackage($composerName);

    CapellExtension::query()->create([
        'composer_name' => $composerName,
        'name' => 'Blocked Paid',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'revoked',
        'marketplace_runtime_allowed' => false,
        'marketplace_signed_activation' => [
            'activation_id' => 'act_blocked',
            'signature_algorithm' => 'hmac-sha256',
            'signature_issued_at' => now()->subMinute()->toIso8601String(),
            'signature' => 'signed',
        ],
    ]);

    expect(CapellCore::isPackageEnabled($composerName))->toBeFalse()
        ->and(CapellCore::isPackageInstalled($composerName))->toBeFalse();
});

it('refreshes extension runtime gates when extension caches are cleared', function (): void {
    $composerName = 'capell-app/runtime-refresh-' . bin2hex(random_bytes(4));

    CapellCore::registerPackage($composerName);

    $extension = CapellExtension::query()->create([
        'composer_name' => $composerName,
        'name' => 'Runtime Refresh',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'revoked',
        'marketplace_runtime_allowed' => false,
    ]);

    expect(CapellCore::isPackageEnabled($composerName))->toBeFalse();

    $extension->forceFill([
        'marketplace_runtime_status' => 'active',
        'marketplace_runtime_allowed' => true,
    ])->save();

    CapellCore::clearExtensionCache();

    expect(CapellCore::isPackageEnabled($composerName))->toBeTrue();
});

it('bulk loads extension records when checking multiple registered packages', function (): void {
    CapellCore::clearPackages();

    $packageNames = [
        'capell-app/bulk-load-one-' . bin2hex(random_bytes(4)),
        'capell-app/bulk-load-two-' . bin2hex(random_bytes(4)),
        'capell-app/bulk-load-three-' . bin2hex(random_bytes(4)),
    ];

    foreach ($packageNames as $packageName) {
        CapellCore::registerPackage($packageName);

        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'name' => $packageName,
            'status' => ExtensionStatusEnum::Enabled,
            'is_paid_marketplace_extension' => false,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ($packageNames as $packageName) {
        expect(CapellCore::isPackageEnabled($packageName))->toBeTrue();
    }

    $extensionQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
        $querySql = $query['query'];

        return str_contains($querySql, 'from "capell_extensions"');
    });

    DB::disableQueryLog();

    expect($extensionQueries)->toHaveCount(1);
});

it('retries package installation state after an extension record is created later in the request', function (): void {
    $packageName = 'capell-app/later-enabled-' . bin2hex(random_bytes(4));

    CapellCore::registerPackage($packageName);

    expect(CapellCore::isPackageInstalled($packageName))->toBeFalse()
        ->and(CapellCore::getPackage($packageName)->isInstalled())->toBeFalse();

    CapellExtension::query()->create([
        'composer_name' => $packageName,
        'name' => $packageName,
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => false,
    ]);

    expect(CapellCore::isPackageInstalled($packageName))->toBeTrue()
        ->and(CapellCore::getPackage($packageName)->isInstalled())->toBeTrue();
});

it('keeps core package lifecycle state in the uninstall cache instead of extension records', function (): void {
    CapellCore::clearPackages();

    $packagePath = makeComposerPackageFixture('capell-app/admin');

    CapellCore::registerPackage('capell-app/admin', path: $packagePath);
    CapellCore::markPackageInstalling('capell-app/admin');
    CapellCore::markPackageFailed('capell-app/admin', 'Installer failed');

    expect(CapellExtension::query()->where('composer_name', 'capell-app/admin')->exists())->toBeFalse();

    CapellCore::markPackageUninstalled('capell-app/admin');

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeFalse()
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/admin')->exists())->toBeTrue();

    CapellCore::markPackageInstalled('capell-app/admin');

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue()
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/admin')->exists())->toBeFalse();
});

it('checks package requirements while treating core dependencies as already satisfied', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/feature');

    expect(CapellCore::arePackageRequirementsInstalled([
        'capell-app/core',
        'capell-app/capell',
        'vendor/feature',
    ]))->toBeTrue()
        ->and(CapellCore::arePackageRequirementsInstalled(['vendor/missing']))->toBeFalse()
        ->and(CapellCore::canInstallPackage('vendor/missing'))->toBeFalse()
        ->and(CapellCore::canUninstallPackage('vendor/missing'))->toBeFalse();
});

it('normalizes cached uninstalled extension names before checking enabled state', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/cached-disabled');
    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, collect([
        'vendor/cached-disabled',
        '',
        123,
    ]), ttl: 0);

    expect(CapellCore::isPackageEnabled('vendor/cached-disabled'))->toBeFalse();
});

it('maps manifest kinds to installable package types', function (): void {
    CapellCore::clearPackages();

    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/integration-kind',
        overrides: ['kind' => 'integration'],
    )));
    CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/plugin-kind',
        overrides: ['kind' => 'plugin'],
    )));

    expect(CapellCore::getPackage('vendor/integration-kind')->type)->toBe(PackageTypeEnum::Integration)
        ->and(CapellCore::getPackage('vendor/plugin-kind')->type)->toBe(PackageTypeEnum::Plugin);
});

function makePackageManifestFixture(array $overrides): string
{
    $packagePath = sys_get_temp_dir() . '/capell-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    $manifest = array_merge([
        ...capellManifestV3Array('vendor/package', ['admin']),
    ], $overrides);

    file_put_contents(
        $packagePath . '/capell.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}

function makeComposerPackageFixture(string $composerName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-composer-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => $composerName], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}

function makeCoreManifestComposerPackageFixture(string $composerName): string
{
    $packagePath = makeComposerPackageFixture($composerName);

    file_put_contents(
        $packagePath . '/capell.json',
        json_encode([
            ...capellManifestV3Array($composerName, ['admin']),
            'name' => $composerName,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}
