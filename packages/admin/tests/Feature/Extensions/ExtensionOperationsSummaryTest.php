<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionDependencyGraphAction;
use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Extensions\BuildExtensionRuntimeCompatibilityAction;
use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Actions\Extensions\ListExtensionAuditEventsAction;
use Capell\Admin\Actions\Extensions\RepairComposerDriftAction;
use Capell\Admin\Contracts\Extensions\ExtensionDependencyProvider;
use Capell\Admin\Contracts\Extensions\ExtensionRuntimeCheckProvider;
use Capell\Admin\Contracts\Extensions\ExtensionUpdateMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionDependencyBlockData;
use Capell\Admin\Data\Extensions\ExtensionOperationPackageData;
use Capell\Admin\Data\Extensions\ExtensionRuntimeCompatibilityData;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Admin\Support\Extensions\ComposerDriftMetadata;
use Capell\Admin\Tests\Feature\Extensions\Fixtures\ExtensionOperationsSummaryAccessiblePage;
use Capell\Admin\Tests\Feature\Extensions\Fixtures\ExtensionOperationsSummaryInaccessiblePage;
use Capell\Core\Actions\RequirePackageAction;
use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)->group('extension');

afterEach(function (): void {
    RequirePackageAction::resetProcessFactory();
});

beforeEach(function (): void {
    CapellCore::clearPackages();
    CapellCore::clearExtensionCache();
    BuildExtensionOperationsSummaryAction::forgetRequestCache();
    resolve(CapellPackageRegistry::class)->fill([]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return true;
        }

        public function has(string $composerName): bool
        {
            return false;
        }

        public function version(string $composerName): string
        {
            return '1.0.0';
        }
    });
});

it('builds downstream extension update, dependency, runtime, and audit surfaces from the operations summary', function (): void {
    app()->instance('testing.update-readiness-provider', new class implements ExtensionUpdateMetadataProvider
    {
        public function updateReadiness(ExtensionOperationPackageData $package): ?ExtensionUpdateReadinessData
        {
            if ($package->packageName !== 'vendor/provider-override') {
                return null;
            }

            return new ExtensionUpdateReadinessData(
                packageName: $package->packageName,
                state: 'provider_review',
                currentVersion: $package->version,
                latestVersion: $package->latestVersion,
                blocker: 'provider policy requires manual review',
            );
        }
    });
    app()->tag(['testing.update-readiness-provider'], ExtensionUpdateMetadataProvider::TAG);

    app()->instance('testing.extension-dependency-provider', new class implements ExtensionDependencyProvider
    {
        public function blockers(ExtensionOperationPackageData $package): array
        {
            if ($package->packageName !== 'vendor/runtime-blocked') {
                return [];
            }

            return [
                new ExtensionDependencyBlockData(
                    packageName: 'vendor/runtime-blocked',
                    blockedPackageName: 'vendor/editorial-suite',
                    operation: 'update',
                    reason: 'requires_reviewed_license',
                ),
            ];
        }
    });
    app()->tag(['testing.extension-dependency-provider'], ExtensionDependencyProvider::TAG);

    app()->instance('testing.extension-runtime-provider', new class implements ExtensionRuntimeCheckProvider
    {
        public function checks(ExtensionOperationPackageData $package): array
        {
            if ($package->packageName !== 'vendor/major-jump') {
                return [];
            }

            return [
                new ExtensionRuntimeCompatibilityData(
                    packageName: $package->packageName,
                    state: 'warning',
                    requirements: ['php >= 8.4'],
                    message: 'Provider detected a runtime advisory.',
                ),
            ];
        }
    });
    app()->tag(['testing.extension-runtime-provider'], ExtensionRuntimeCheckProvider::TAG);

    registerOperationsSummaryManifest('capell-app/core', [
        'displayName' => 'Capell Core',
        'version' => '4.0.0',
    ]);
    CapellCore::forcePackageInstalled('capell-app/core');
    registerOperationsSummaryManifest('vendor/core-dependent', [
        'displayName' => 'Core Dependent',
        'version' => '1.0.0',
        'dependencies' => [
            'requires' => ['capell-app/core'],
        ],
    ]);
    CapellCore::forcePackageInstalled('vendor/core-dependent');

    foreach ([
        'vendor/major-jump' => ['version' => '1.2.3', 'latest_version' => '2.0.0'],
        'vendor/minor-jump' => ['version' => '1.2.3', 'latest_version' => '1.3.0'],
        'vendor/patch-jump' => ['version' => '1.2.3', 'latest_version' => '1.2.4'],
        'vendor/current' => ['version' => '1.2.3', 'latest_version' => '1.2.3'],
        'vendor/unknown' => ['version' => '1.2.3', 'latest_version' => null],
        'vendor/provider-override' => ['version' => '1.2.3', 'latest_version' => '1.4.0'],
        'vendor/runtime-blocked' => ['version' => '1.0.0', 'latest_version' => null],
        'vendor/uninstalled' => ['version' => '1.0.0', 'latest_version' => null],
    ] as $packageName => $metadata) {
        registerOperationsSummaryManifest($packageName, [
            'displayName' => str($packageName)->after('/')->headline()->toString(),
            'version' => $metadata['version'],
        ]);

        if ($packageName === 'vendor/uninstalled') {
            continue;
        }

        CapellExtension::query()->create([
            'composer_name' => $packageName,
            'name' => str($packageName)->after('/')->headline()->toString(),
            'version' => $metadata['version'],
            'status' => ExtensionStatusEnum::Enabled,
            'installed_at' => now(),
            'metadata' => array_filter([
                'latest_version' => $metadata['latest_version'],
            ], static fn (?string $value): bool => $value !== null),
            'is_paid_marketplace_extension' => $packageName === 'vendor/runtime-blocked',
            'marketplace_runtime_status' => $packageName === 'vendor/runtime-blocked' ? 'revoked' : null,
            'marketplace_runtime_allowed' => $packageName !== 'vendor/runtime-blocked',
        ]);
    }

    BuildExtensionOperationsSummaryAction::forgetRequestCache();

    $readiness = collect(BuildExtensionUpdateReadinessAction::run())->keyBy('packageName');
    $dependencyBlocks = collect(BuildExtensionDependencyGraphAction::run());
    $runtimeChecks = collect(BuildExtensionRuntimeCompatibilityAction::run())->groupBy('packageName');
    $auditEvents = collect(ListExtensionAuditEventsAction::run(limit: 3));

    expect($readiness->get('vendor/major-jump')?->state)->toBe('major_review')
        ->and($readiness->get('vendor/minor-jump')?->state)->toBe('minor_ready')
        ->and($readiness->get('vendor/patch-jump')?->state)->toBe('patch_ready')
        ->and($readiness->get('vendor/current')?->state)->toBe('none')
        ->and($readiness->get('vendor/unknown')?->state)->toBe('unknown')
        ->and($readiness->get('vendor/runtime-blocked')?->state)->toBe('blocked')
        ->and($readiness->get('vendor/provider-override')?->state)->toBe('provider_review')
        ->and($readiness->get('vendor/provider-override')?->blocker)->toBe('provider policy requires manual review')
        ->and($dependencyBlocks->firstWhere('packageName', 'capell-app/core')?->reason)->toBe('protected_core_package')
        ->and($dependencyBlocks->firstWhere('packageName', 'vendor/runtime-blocked')?->reason)->toBe('requires_reviewed_license')
        ->and($runtimeChecks->get('vendor/current')?->first()?->state)->toBe('pass')
        ->and($runtimeChecks->get('vendor/runtime-blocked')?->first()?->state)->toBe('blocked')
        ->and($runtimeChecks->get('vendor/runtime-blocked')?->first()?->message)->toBe('revoked')
        ->and($runtimeChecks->get('vendor/major-jump')?->last()?->message)->toBe('Provider detected a runtime advisory.')
        ->and($auditEvents)->toHaveCount(3)
        ->and($auditEvents->pluck('packageName')->all())->not->toContain('vendor/uninstalled');
});

it('alerts when an installed extension record no longer has a composer package manifest', function (): void {
    CapellExtension::query()->create([
        'composer_name' => 'vendor/missing-composer-entry',
        'name' => 'Missing Composer Entry',
        'description' => 'Previously installed extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'metadata' => [
            'product_group' => 'Publishing',
            'tier' => 'premium',
        ],
    ]);

    $summary = BuildExtensionOperationsSummaryAction::run();
    $package = $summary->package('vendor/missing-composer-entry');
    $alert = $package?->healthAlerts[0] ?? null;

    expect($package)->not->toBeNull()
        ->and($package->installed)->toBeTrue()
        ->and($package->available)->toBeFalse()
        ->and($package->blocked)->toBeTrue()
        ->and($package->needsAttention)->toBeTrue()
        ->and($package->healthState)->toBe('warning')
        ->and($alert)->not->toBeNull()
        ->and($alert->id)->toBe('composer_drift_' . hash('sha256', 'vendor/missing-composer-entry'))
        ->and($alert->severity)->toBe(ExtensionHealthAlertSeverity::Warning->value)
        ->and($alert->category)->toBe(ExtensionHealthAlertCategory::Package->value)
        ->and($summary->needsAttentionCount)->toBe(1)
        ->and($summary->blockedCount)->toBe(1)
        ->and($summary->installedCount)->toBe(1)
        ->and($summary->availableCount)->toBe(0)
        ->and($alert?->message)->toContain('current registry has no manifest');
});

it('alerts when a registered installed extension is missing from composer availability', function (): void {
    registerOperationsSummaryManifest('vendor/registered-but-missing', [
        'displayName' => 'Registered But Missing',
        'version' => '1.0.0',
    ]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return false;
        }
    });

    CapellExtension::query()->create([
        'composer_name' => 'vendor/registered-but-missing',
        'name' => 'Registered But Missing',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $summary = BuildExtensionOperationsSummaryAction::run();
    $package = $summary->package('vendor/registered-but-missing');
    $alert = $package?->healthAlerts[0] ?? null;

    expect($package)->not->toBeNull()
        ->and($package->installed)->toBeTrue()
        ->and($package->available)->toBeFalse()
        ->and($package->needsAttention)->toBeTrue()
        ->and($package->healthState)->toBe('warning')
        ->and($alert?->category)->toBe(ExtensionHealthAlertCategory::Package->value)
        ->and($summary->needsAttentionCount)->toBe(1)
        ->and($summary->availableCount)->toBe(0)
        ->and($alert?->message)->toContain('the registry manifest exists, but Composer does not expose the package');
});

it('does not attempt to restore composer drift while building dashboard summaries', function (): void {
    config()->set('capell-admin.extensions.composer_drift.auto_fix', true);

    CapellExtension::query()->create([
        'composer_name' => 'vendor/auto-fixed-extension',
        'name' => 'Auto Fixed Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $requiredPackages = [];

    RequirePackageAction::setProcessFactory(function (array $arguments, string $cwd, ?array $environment) use (&$requiredPackages): object {
        $requiredPackages[] = $arguments;

        return new class
        {
            public function setTimeout(int $timeout): void
            {
                //
            }

            public function run(): void
            {
                //
            }

            public function getErrorOutput(): string
            {
                return '';
            }

            public function getOutput(): string
            {
                return 'composer require completed';
            }

            public function isSuccessful(): bool
            {
                return true;
            }
        };
    });

    BuildExtensionOperationsSummaryAction::run();

    expect($requiredPackages)->toBe([]);
});

it('alerts when composer exposes a different extension version than the capell record', function (): void {
    registerOperationsSummaryManifest('vendor/version-drift', [
        'displayName' => 'Version Drift',
        'version' => '2.0.0',
    ]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return true;
        }

        public function version(string $composerName): string
        {
            return '2.0.0';
        }
    });

    CapellExtension::query()->create([
        'composer_name' => 'vendor/version-drift',
        'name' => 'Version Drift',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $summary = BuildExtensionOperationsSummaryAction::run();
    $package = $summary->package('vendor/version-drift');
    $alert = $package?->healthAlerts[0] ?? null;

    expect($package)->not->toBeNull()
        ->and($package->needsAttention)->toBeTrue()
        ->and($alert?->message)->toContain('different package version');
});

it('records successful composer drift repair metadata and clears extension caches', function (): void {
    registerOperationsSummaryManifest('vendor/repairable-extension', [
        'displayName' => 'Repairable Extension',
        'version' => '1.0.0',
    ]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return false;
        }
    });

    CapellExtension::query()->create([
        'composer_name' => 'vendor/repairable-extension',
        'name' => 'Repairable Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $requiredPackages = [];

    RequirePackageAction::setProcessFactory(function (array $arguments, string $cwd, ?array $environment) use (&$requiredPackages): object {
        $requiredPackages[] = $arguments;

        return new class
        {
            public function setTimeout(int $timeout): void
            {
                //
            }

            public function run(): void
            {
                //
            }

            public function getErrorOutput(): string
            {
                return '';
            }

            public function getOutput(): string
            {
                return 'composer require completed';
            }

            public function isSuccessful(): bool
            {
                return true;
            }
        };
    });

    $results = RepairComposerDriftAction::run('vendor/repairable-extension');
    $extension = CapellExtension::query()->where('composer_name', 'vendor/repairable-extension')->firstOrFail();

    expect($requiredPackages)->toBe([
        ['composer', 'require', 'vendor/repairable-extension'],
    ])
        ->and($results[0]['status'] ?? null)->toBe('success')
        ->and($extension->metadata[ComposerDriftMetadata::LAST_DETECTED_REASON] ?? null)->toBe('composer_unavailable')
        ->and($extension->metadata[ComposerDriftMetadata::LAST_REPAIR_STATUS] ?? null)->toBe('success')
        ->and($extension->metadata[ComposerDriftMetadata::LAST_REPAIR_MESSAGE] ?? null)->toContain('installed successfully');
});

it('records failed composer drift repair metadata without throwing silently', function (): void {
    registerOperationsSummaryManifest('vendor/failing-repair-extension', [
        'displayName' => 'Failing Repair Extension',
        'version' => '1.0.0',
    ]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return false;
        }
    });

    CapellExtension::query()->create([
        'composer_name' => 'vendor/failing-repair-extension',
        'name' => 'Failing Repair Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    RequirePackageAction::setProcessFactory(fn (): object => new class
    {
        public function setTimeout(int $timeout): void
        {
            //
        }

        public function run(): void
        {
            //
        }

        public function getErrorOutput(): string
        {
            return 'composer failed';
        }

        public function getOutput(): string
        {
            return '';
        }

        public function isSuccessful(): bool
        {
            return false;
        }
    });

    $results = RepairComposerDriftAction::run('vendor/failing-repair-extension');
    $extension = CapellExtension::query()->where('composer_name', 'vendor/failing-repair-extension')->firstOrFail();

    expect($results[0]['status'] ?? null)->toBe('failed')
        ->and($results[0]['message'] ?? null)->toContain('composer failed')
        ->and($extension->metadata[ComposerDriftMetadata::LAST_REPAIR_STATUS] ?? null)->toBe('failed')
        ->and($extension->metadata[ComposerDriftMetadata::LAST_REPAIR_MESSAGE] ?? null)->toContain('composer failed');
});

it('does not run all-package composer drift repair when the command config gate is disabled', function (): void {
    config()->set('capell-admin.extensions.composer_drift.auto_fix', false);

    $requiredPackages = [];
    RequirePackageAction::setProcessFactory(function (array $arguments) use (&$requiredPackages): object {
        $requiredPackages[] = $arguments;

        return new class
        {
            public function setTimeout(int $timeout): void
            {
                //
            }

            public function run(): void
            {
                //
            }

            public function getErrorOutput(): string
            {
                return '';
            }

            public function getOutput(): string
            {
                return 'composer require completed';
            }

            public function isSuccessful(): bool
            {
                return true;
            }
        };
    });

    artisanCommand('capell:extensions:repair-composer-drift --all')
        ->expectsOutput('Composer drift auto-repair is disabled. Set CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX=true or pass --force.')
        ->assertSuccessful();

    expect($requiredPackages)->toBe([]);
});

it('repairs an explicit composer drift package from the command when the config gate is disabled', function (): void {
    config()->set('capell-admin.extensions.composer_drift.auto_fix', false);

    registerOperationsSummaryManifest('vendor/explicit-repair-extension', [
        'displayName' => 'Explicit Repair Extension',
        'version' => '1.0.0',
    ]);

    app()->instance(InstalledExtensionRepository::class, new class
    {
        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return false;
        }
    });

    CapellExtension::query()->create([
        'composer_name' => 'vendor/explicit-repair-extension',
        'name' => 'Explicit Repair Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $requiredPackages = [];

    RequirePackageAction::setProcessFactory(function (array $arguments) use (&$requiredPackages): object {
        $requiredPackages[] = $arguments;

        return new class
        {
            public function setTimeout(int $timeout): void
            {
                //
            }

            public function run(): void
            {
                //
            }

            public function getErrorOutput(): string
            {
                return '';
            }

            public function getOutput(): string
            {
                return 'composer require completed';
            }

            public function isSuccessful(): bool
            {
                return true;
            }
        };
    });

    artisanCommand('capell:extensions:repair-composer-drift vendor/explicit-repair-extension')
        ->expectsOutputToContain('[success] vendor/explicit-repair-extension')
        ->assertSuccessful();

    expect($requiredPackages)->toBe([
        ['composer', 'require', 'vendor/explicit-repair-extension'],
    ]);
});

it('builds an operations summary from manifests, extension records, health alerts, and safe management links', function (): void {
    test()->actingAsAdmin();

    Permission::create(['name' => 'View:EditorialTools', 'guard_name' => 'web']);
    test()->authenticatedUser()->givePermissionTo('View:EditorialTools');

    Route::get('/admin/editorial-tools', fn (): string => 'editorial')->name('capell.admin.editorial-tools');
    Route::getRoutes()->refreshNameLookups();

    registerOperationsSummaryManifest('vendor/editorial-tools', [
        'displayName' => 'Editorial Tools',
        'version' => '1.0.0',
        'product' => ['group' => 'Publishing', 'tier' => 'premium', 'bundle' => 'publishing'],
        'database' => ['migrations' => true, 'settings' => false, 'requiredTables' => ['missing_editorial_tables']],
        'commercial' => [
            'proposedLicense' => 'paid',
            'requestedCertification' => 'first-party',
            'supportPolicy' => 'priority',
            'privateDocsRequested' => true,
        ],
        'contributes' => [
            [
                'type' => 'admin-page',
                'class' => 'Vendor\\EditorialTools\\Pages\\EditorialToolsPage',
                'label' => 'Editorial tools',
                'permission' => 'View:EditorialTools',
                'managementRoute' => 'capell.admin.editorial-tools',
            ],
            [
                'type' => 'dashboard-widget',
                'class' => 'Vendor\\EditorialTools\\Widgets\\EditorialQueueWidget',
                'surface' => 'admin',
            ],
        ],
    ]);

    registerOperationsSummaryManifest('vendor/blocked-commerce', [
        'displayName' => 'Blocked Commerce',
        'version' => '1.0.0',
        'product' => ['group' => 'Commerce', 'tier' => 'premium', 'bundle' => 'commerce'],
        'commercial' => [
            'proposedLicense' => 'paid',
            'requestedCertification' => 'community',
            'supportPolicy' => 'standard',
            'privateDocsRequested' => false,
        ],
    ]);

    registerOperationsSummaryManifest('vendor/free-tools', [
        'displayName' => 'Free Tools',
        'version' => '1.0.0',
    ]);

    CapellExtension::query()->create([
        'composer_name' => 'vendor/editorial-tools',
        'name' => 'Editorial Tools',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'metadata' => [
            'latest_version' => '1.2.0',
            'certification_status' => 'first-party',
        ],
    ]);

    CapellExtension::query()->create([
        'composer_name' => 'vendor/blocked-commerce',
        'name' => 'Blocked Commerce',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'revoked',
        'marketplace_runtime_allowed' => false,
    ]);

    ExtensionHealthAlert::query()->create([
        'alert_id' => 'alert_editorial_critical',
        'source' => 'marketplace',
        'composer_name' => 'vendor/editorial-tools',
        'severity' => ExtensionHealthAlertSeverity::Critical,
        'category' => ExtensionHealthAlertCategory::Security,
        'title' => 'Critical security advisory',
        'message' => 'Upgrade editorial tools immediately.',
        'required_action' => 'upgrade',
        'runtime_disabled' => false,
        'protected_actions_blocked' => true,
        'issued_at' => now(),
        'signature' => 'test-signature',
    ]);

    $summary = BuildExtensionOperationsSummaryAction::run();
    $editorialPackage = $summary->package('vendor/editorial-tools');
    $blockedPackage = $summary->package('vendor/blocked-commerce');

    expect($summary->installedCount)->toBe(2)
        ->and($summary->uninstalledCount)->toBe(1)
        ->and($summary->availableCount)->toBe(3)
        ->and($summary->blockedCount)->toBe(1)
        ->and($summary->updatesCount)->toBe(1)
        ->and($summary->needsAttentionCount)->toBe(2)
        ->and($editorialPackage['contributionCount'] ?? null)->toBe(2)
        ->and($editorialPackage['latestVersion'] ?? null)->toBe('1.2.0')
        ->and($editorialPackage['missingRequiredTables'] ?? [])->toBe(['missing_editorial_tables'])
        ->and($editorialPackage['healthState'] ?? null)->toBe('critical')
        ->and(str_contains((string) ($editorialPackage['managementEntries'][0]['url'] ?? ''), '/admin/editorial-tools'))->toBeTrue()
        ->and($blockedPackage['runtimeStatus'] ?? null)->toBe('revoked')
        ->and($blockedPackage['blocked'] ?? null)->toBeTrue()
        ->and($blockedPackage['premiumMissingMarketplaceAccount'] ?? null)->toBeTrue();
});

it('builds extension operation cards with safe image, management, and alert fallbacks', function (): void {
    config([
        'capell-marketplace.marketplace.web_url' => null,
        'capell.marketplace_web_url' => 'https://capell-test.app',
    ]);

    Route::get('/admin/visual-extension/{tab}', fn (string $tab): string => $tab)->name('capell.admin.visual-extension');
    Route::getRoutes()->refreshNameLookups();

    registerOperationsSummaryManifest('vendor/visual-extension', [
        'displayName' => 'Visual Extension',
        'version' => '1.0.0',
        'product' => ['group' => '', 'tier' => 'free', 'bundle' => null],
        'marketplace' => [
            'screenshots' => [
                [
                    'path' => '',
                    'alt' => 'Blank screenshot should be ignored',
                    'caption' => 'Blank screenshot',
                ],
                [
                    'path' => 'https://cdn.example.test/visual-extension.jpg',
                    'alt' => 'Remote screenshot',
                    'caption' => 'Remote screenshot',
                ],
                [
                    'path' => 'images/visual-extension.jpg',
                    'alt' => 'Marketplace screenshot',
                    'caption' => 'Marketplace screenshot',
                ],
            ],
        ],
        'contributes' => [
            [
                'type' => 'admin-page',
                'pageClass' => ExtensionOperationsSummaryAccessiblePage::class,
                'pageParameters' => [
                    'tab' => 'settings',
                    'numeric' => 12,
                    'ignored' => ['nested'],
                ],
            ],
            [
                'type' => 'admin-page',
                'label' => 'Hidden page',
                'pageClass' => ExtensionOperationsSummaryInaccessiblePage::class,
            ],
            [
                'type' => 'admin-page',
                'label' => 'Denied route',
                'permission' => 'View:DeniedRoute',
                'managementRoute' => 'capell.admin.visual-extension',
                'routeParameters' => ['tab' => 'denied'],
            ],
            [
                'type' => 'admin-page',
                'labelKey' => 'capell-admin::navigation.settings',
                'managementRoute' => 'capell.admin.visual-extension',
                'routeParameters' => [
                    'tab' => 'overview',
                    'ignored' => ['nested'],
                ],
            ],
        ],
    ]);

    CapellCore::getPackage('vendor/visual-extension')->previewImageUrl = '/package-card.jpg';

    CapellExtension::query()->create([
        'composer_name' => 'vendor/visual-extension',
        'name' => 'Visual Extension',
        'version' => '1.0.0',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
        'metadata' => [
            'certification_status' => 'first party',
            'product_group' => '',
        ],
    ]);

    ExtensionHealthAlert::query()->create([
        'alert_id' => 'visual_info',
        'source' => 'marketplace',
        'composer_name' => 'vendor/visual-extension',
        'severity' => ExtensionHealthAlertSeverity::Info,
        'category' => ExtensionHealthAlertCategory::Package,
        'title' => 'Informational notice',
        'message' => 'Review the visual extension configuration.',
        'required_action' => 'review',
        'runtime_disabled' => false,
        'protected_actions_blocked' => false,
        'issued_at' => now(),
        'signature' => 'visual-signature',
    ]);

    $summary = BuildExtensionOperationsSummaryAction::run();
    $package = $summary->package('vendor/visual-extension');

    expect($package)->not->toBeNull()
        ->and($package->imageUrl)->toBe('/package-card.jpg')
        ->and($package->imageUrls)->toContain(
            '/package-card.jpg',
            'https://cdn.example.test/visual-extension.jpg',
            'https://capell-test.app/images/visual-extension.jpg',
        )
        ->and($package->healthState)->toBe('info')
        ->and($package->certification)->toBe('first-party')
        ->and($package->productGroup)->toBe('Other')
        ->and($package->managementEntries)->toHaveCount(2)
        ->and($package->managementEntries[0]['label'])->toBe('Admin Page')
        ->and($package->managementEntries[0]['url'])->toContain('tab=settings', 'numeric=12')
        ->and($package->managementEntries[1]['url'])->toContain('/admin/visual-extension/overview')
        ->and($package->healthAlerts[0]->managementUrl)->toBe($package->managementEntries[0]['url'])
        ->and($summary->alerts[0]->managementLabel)->toBe('Admin Page');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function registerOperationsSummaryManifest(string $packageName, array $overrides = []): void
{
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: $packageName,
        surfaces: ['admin'],
        overrides: $overrides,
    ));

    /** @var CapellPackageRegistry $registry */
    $registry = resolve(CapellPackageRegistry::class);
    $registry->fill([
        ...$registry->all(),
        $manifest->name => $manifest,
    ]);

    CapellCore::registerManifestPackage($manifest);
}
