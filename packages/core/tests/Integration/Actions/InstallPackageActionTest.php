<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallStateLifecycleRecorderAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\RuntimeProviderInstallPackageFixture;
use Capell\Core\Tests\Support\Fixtures\Autoload\ThrowingRuntimeProviderInstallPackageFixture;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

it('installs a package with filesystem side-effects', function (): void {
    File::spy();

    // Register the package in CapellCore (simulate real registration)
    CapellCore::registerPackage('vendor/package', version: '^1.0');

    $package = new PackageData(
        name: 'vendor/package',
        type: PackageTypeEnum::Plugin,
        version: '^1.0',
    );

    InstallPackageAction::run($package);

    expect(CapellCore::isPackageInstalled('vendor/package'))->toBeTrue();
});

it('handles install failures', function (): void {
    // Register a package with a missing requirement
    CapellCore::registerPackage('invalid/package', path: realpath(__DIR__ . '/../../../../../tests/fixtures/requirements-package'), version: '^0.0');

    $package = new PackageData(
        name: 'invalid/package',
        type: PackageTypeEnum::Plugin,
        version: '^0.0',
        requirements: ['dep1'],
    );

    expect(fn () => InstallPackageAction::run($package))
        ->toThrow(Exception::class, 'cannot be installed. Missing required plugin(s): dep1.');
});

it('installs a package with no requirements', function (): void {
    File::spy();

    CapellCore::registerPackage('vendor/no-req-package', version: '^1.0');

    $package = new PackageData(
        name: 'vendor/no-req-package',
        type: PackageTypeEnum::Plugin,
        version: '^1.0',
        requirements: [],
    );

    InstallPackageAction::run($package);

    expect(CapellCore::isPackageInstalled('vendor/no-req-package'))->toBeTrue();
});

it('installs and enables every bundle member before the bundle', function (): void {
    CapellCore::registerPackage('vendor/member-one', version: '^0.0');
    CapellCore::registerPackage('vendor/member-two', version: '^0.0');
    CapellCore::registerPackage('vendor/showcase', version: '^0.0');
    $bundle = CapellCore::getPackage('vendor/showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member-one', 'vendor/member-two'];

    InstallPackageAction::run($bundle);

    expect(CapellCore::isPackageInstalled('vendor/member-one'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/member-two'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/showcase'))->toBeTrue();

    CapellCore::markPackageUninstalled('vendor/member-one');
    CapellCore::markPackageUninstalled('vendor/member-two');
    EnablePackageAction::run($bundle);

    expect(CapellCore::isPackageInstalled('vendor/member-one'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/member-two'))->toBeTrue();
});

it('rolls back newly activated bundle members when installation fails', function (): void {
    CapellCore::registerPackage('vendor/member-good', version: '^0.0');
    CapellCore::registerPackage('vendor/member-broken', version: '^0.0');
    CapellCore::getPackage('vendor/member-broken')->requirements = ['vendor/missing'];
    CapellCore::registerPackage('vendor/failing-showcase', version: '^0.0');
    $bundle = CapellCore::getPackage('vendor/failing-showcase');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/member-good', 'vendor/member-broken'];

    expect(fn () => InstallPackageAction::run($bundle))->toThrow(Exception::class);

    expect(CapellCore::isPackageInstalled('vendor/member-good'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/member-broken'))->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/failing-showcase'))->toBeFalse();
});

it('runs install lifecycle actions without requiring artisan command registration', function (): void {
    LifecycleRecorderAction::reset();

    CapellCore::registerPackage('vendor/action-install-package', version: '^1.0');

    $package = new PackageData(
        name: 'vendor/action-install-package',
        type: PackageTypeEnum::Plugin,
        installCommand: 'vendor:missing-install-command',
        installAction: LifecycleRecorderAction::class,
        installParams: ['--seed'],
        version: '^1.0',
    );

    InstallPackageAction::run($package, ['--seed'], null, false);

    expect(CapellCore::isPackageInstalled('vendor/action-install-package'))->toBeTrue()
        ->and(LifecycleRecorderAction::$calls)->toBe([
            [
                'package' => 'vendor/action-install-package',
                'arguments' => ['--seed'],
            ],
        ]);
});

it('records installed extension metadata when a package is installed', function (): void {
    CapellCore::registerPackage('vendor/tracked-package', version: '1.2.3');

    InstallPackageAction::run(CapellCore::getPackage('vendor/tracked-package'));

    $extension = expectPresent(CapellExtension::query()->where('composer_name', 'vendor/tracked-package')->first());

    expect($extension)->not->toBeNull()
        ->and($extension->version)->toBe('1.2.3')
        ->and($extension->status)->toBe(ExtensionStatusEnum::Enabled)
        ->and($extension->enabled_at)->not->toBeNull()
        ->and($extension->failed_at)->toBeNull()
        ->and($extension->installed_at)->not->toBeNull();
});

it('registers runtime providers after installing a package', function (): void {
    RuntimeProviderInstallPackageFixture::$registered = false;

    $packagePath = makeInstallActionManifestPackageFixture(
        composerName: 'vendor/runtime-provider-package',
        runtimeProvider: RuntimeProviderInstallPackageFixture::class,
    );

    CapellCore::registerPackage(
        name: 'vendor/runtime-provider-package',
        path: $packagePath,
    );

    InstallPackageAction::run(CapellCore::getPackage('vendor/runtime-provider-package'));

    expect(RuntimeProviderInstallPackageFixture::$registered)->toBeTrue();
});

it('records failed state when runtime provider registration throws after the installed transition', function (): void {
    $packageName = 'vendor/failing-runtime-provider-package';
    $packagePath = makeInstallActionManifestPackageFixture(
        composerName: $packageName,
        runtimeProvider: ThrowingRuntimeProviderInstallPackageFixture::class,
    );
    ThrowingRuntimeProviderInstallPackageFixture::reset($packageName);

    try {
        CapellCore::registerPackage($packageName, path: $packagePath);

        expect(fn (): null => InstallPackageAction::run(CapellCore::getPackage($packageName)))
            ->toThrow(RuntimeException::class, 'Runtime provider registration failed.');

        $extension = CapellExtension::query()
            ->where('composer_name', $packageName)
            ->firstOrFail();

        expect(ThrowingRuntimeProviderInstallPackageFixture::$calls)->toBe(1)
            ->and(ThrowingRuntimeProviderInstallPackageFixture::$observedStatus)->toBe(ExtensionStatusEnum::Enabled)
            ->and(ThrowingRuntimeProviderInstallPackageFixture::$observedInstalled)->toBeTrue()
            ->and($extension->status)->toBe(ExtensionStatusEnum::Failed)
            ->and(CapellCore::isPackageInstalled($packageName))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('does not leave a throwing runtime provider bundle member installed', function (): void {
    $goodMemberName = 'vendor/provider-bundle-member-good';
    $failingMemberName = 'vendor/provider-bundle-member-failing';
    $bundleName = 'vendor/provider-failing-showcase';
    $failingMemberPath = makeInstallActionManifestPackageFixture(
        composerName: $failingMemberName,
        runtimeProvider: ThrowingRuntimeProviderInstallPackageFixture::class,
    );
    ThrowingRuntimeProviderInstallPackageFixture::reset($failingMemberName);

    try {
        CapellCore::registerPackage($goodMemberName, version: '^0.0');
        CapellCore::registerPackage($failingMemberName, path: $failingMemberPath, version: '^0.0');
        CapellCore::registerPackage($bundleName, version: '^0.0');

        $bundle = CapellCore::getPackage($bundleName);
        $bundle->kind = 'bundle';
        $bundle->requirements = [$goodMemberName, $failingMemberName];

        expect(fn (): null => InstallPackageAction::run($bundle))
            ->toThrow(RuntimeException::class, 'Runtime provider registration failed.');

        $failedMember = CapellExtension::query()
            ->where('composer_name', $failingMemberName)
            ->firstOrFail();

        expect(CapellCore::isPackageInstalled($goodMemberName))->toBeFalse()
            ->and(CapellCore::isPackageInstalled($failingMemberName))->toBeFalse()
            ->and(CapellCore::isPackageInstalled($bundleName))->toBeFalse()
            ->and($failedMember->status)->toBe(ExtensionStatusEnum::Failed);
    } finally {
        File::deleteDirectory($failingMemberPath);
    }
});

it('tracks core package uninstall state outside the extension lifecycle ledger', function (): void {
    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: makeInstallActionComposerPackageFixture('capell-app/admin'),
        version: '1.2.3',
    );

    InstallPackageAction::run(CapellCore::getPackage('capell-app/admin'));
    CapellCore::markPackageDisabled('capell-app/admin');
    CapellCore::markPackageUninstalled('capell-app/admin');
    CapellCore::removeCacheKey(CacheEnum::ExtensionUninstalledNames->value);

    expect(CapellExtension::query()->where('composer_name', 'capell-app/admin')->value('status'))->toBe(ExtensionStatusEnum::Uninstalled)
        ->and(CapellCore::isPackageInstalled('capell-app/admin'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeFalse();
});

it('honors uninstalled lifecycle state for core packages', function (): void {
    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: makeInstallActionComposerPackageFixture('capell-app/admin'),
        version: '1.2.3',
    );

    CapellExtension::query()->create([
        'composer_name' => 'capell-app/admin',
        'name' => 'Admin',
        'status' => ExtensionStatusEnum::Disabled,
        'disabled_at' => now(),
    ]);
    CapellCore::setToCache(CacheEnum::ExtensionUninstalledNames->value, ['capell-app/admin'], ttl: 0);

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeFalse();
});

it('reinstalls trusted core packages by clearing durable uninstall state', function (): void {
    CapellCore::registerPackage(
        name: 'capell-app/admin',
        path: makeInstallActionComposerPackageFixture('capell-app/admin'),
        version: '1.2.3',
    );

    CapellCore::markPackageUninstalled('capell-app/admin');

    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeFalse();

    CapellCore::markPackageInstalled('capell-app/admin');

    expect(CapellExtension::query()->where('composer_name', 'capell-app/admin')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue()
        ->and(CapellCore::isPackageEnabled('capell-app/admin'))->toBeTrue();
});

it('keeps the original extension installed timestamp when install metadata is refreshed', function (): void {
    CapellCore::registerPackage('vendor/tracked-package', version: '1.2.3');

    InstallPackageAction::run(CapellCore::getPackage('vendor/tracked-package'));
    $installedAt = CapellExtension::query()
        ->where('composer_name', 'vendor/tracked-package')
        ->firstOrFail()
        ->installed_at;

    CapellCore::getPackage('vendor/tracked-package')->version = '1.2.4';
    InstallPackageAction::run(CapellCore::getPackage('vendor/tracked-package'));

    $extension = CapellExtension::query()
        ->where('composer_name', 'vendor/tracked-package')
        ->firstOrFail();

    expect($extension->installed_at?->toIso8601String())->toBe($installedAt?->toIso8601String())
        ->and($extension->version)->toBe('1.2.4');
});

it('refreshes installed extension names when a package is installed', function (): void {
    config()->set('capell-core.disable_cache', false);

    CapellCore::registerPackage('vendor/cache-refresh-package', version: '^1.0');

    expect(CapellCore::getInstalledExtensionNames())->not->toContain('vendor/cache-refresh-package');

    InstallPackageAction::run(CapellCore::getPackage('vendor/cache-refresh-package'));

    expect(CapellCore::getInstalledExtensionNames())->toContain('vendor/cache-refresh-package');
});

it('does not leave a package installed when its install command fails', function (): void {
    Artisan::command('test:package-install-fails', fn (): int => Command::FAILURE);

    CapellCore::registerPackage('vendor/failing-package', version: '^1.0');

    $package = new PackageData(
        name: 'vendor/failing-package',
        type: PackageTypeEnum::Plugin,
        installCommand: 'test:package-install-fails',
        version: '^1.0',
    );

    expect(fn (): null => InstallPackageAction::run($package))
        ->toThrow(Exception::class, 'failed');

    expect(CapellCore::getPackage('vendor/failing-package')->isInstalled())->toBeFalse();
});

it('records failed lifecycle state when package installation fails', function (): void {
    Artisan::command('test:package-lifecycle-install-fails', fn (): int => Command::FAILURE);

    CapellCore::registerPackage('vendor/lifecycle-failing-package', installCommand: 'test:package-lifecycle-install-fails');

    expect(fn (): null => InstallPackageAction::run(CapellCore::getPackage('vendor/lifecycle-failing-package')))
        ->toThrow(Exception::class);

    $extension = CapellExtension::query()
        ->where('composer_name', 'vendor/lifecycle-failing-package')
        ->first();
    $extension = expectPresent($extension);

    $metadata = $extension->metadata ?? [];

    expect($extension)->not->toBeNull()
        ->and($extension->status)->toBe(ExtensionStatusEnum::Failed)
        ->and($extension->failed_at)->not->toBeNull()
        ->and($extension->installed_at)->toBeNull()
        ->and($metadata['install_error'] ?? null)->not->toBe('')
        ->and(CapellCore::isPackageEnabled('vendor/lifecycle-failing-package'))->toBeFalse();
});

it('registers manifest console providers before resolving install commands', function (): void {
    $packagePath = realpath(__DIR__ . '/../../../../../tests/fixtures/manifest-install-command-package');

    CapellCore::registerPackage('vendor/manifest-install-command-package', path: $packagePath, version: '^1.0');

    InstallPackageAction::run(CapellCore::getPackage('vendor/manifest-install-command-package'));

    expect(collect(Artisan::all())->has('test:manifest-install-command'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/manifest-install-command-package'))->toBeTrue();
});

it('publishes and runs declared schema and settings migrations for packages without install lifecycle', function (): void {
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    $packagePath = makeInstallActionDatabasePackageFixture(
        composerName: 'vendor/schema-settings-package',
        declaresSchema: true,
        declaresSettings: true,
        writeSchemaMigration: true,
        writeSettingsMigration: true,
    );

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', [
        '--force' => true,
        '--path' => database_path('migrations'),
        '--realpath' => true,
    ])->andReturn(0)->ordered();
    $kernel->shouldReceive('call')->once()->with('migrate', [
        '--force' => true,
        '--path' => database_path('settings'),
        '--realpath' => true,
    ])->andReturn(0)->ordered();
    $kernel->shouldReceive('output')->twice()->andReturn(
        'Schema migrations applied',
        'Settings migrations applied',
    );
    app()->instance(Kernel::class, $kernel);

    try {
        CapellCore::registerPackage('vendor/schema-settings-package', path: $packagePath, version: '1.0.0');

        InstallPackageAction::run(CapellCore::getPackage('vendor/schema-settings-package'));

        $copiedSources = collect($filesystem->calls)
            ->filter(fn (array $call): bool => $call[0] === 'copy')
            ->pluck(1)
            ->all();
        $extension = CapellExtension::query()
            ->where('composer_name', 'vendor/schema-settings-package')
            ->firstOrFail();

        expect($copiedSources)
            ->toHaveCount(2)
            ->and(collect($copiedSources)->contains(fn (mixed $path): bool => is_string($path) && str_contains($path, '/database/migrations/')))
            ->toBeTrue()
            ->and(collect($copiedSources)->contains(fn (mixed $path): bool => is_string($path) && str_contains($path, '/database/settings/')))
            ->toBeTrue()
            ->and($extension->status)->toBe(ExtensionStatusEnum::Enabled)
            ->and($extension->installed_at)->not->toBeNull()
            ->and(CapellCore::isPackageInstalled('vendor/schema-settings-package'))->toBeTrue();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('marks packages failed when a declared migration directory is missing', function (): void {
    $packagePath = makeInstallActionDatabasePackageFixture(
        composerName: 'vendor/missing-migration-package',
        declaresSchema: true,
        declaresSettings: false,
    );

    try {
        CapellCore::registerPackage('vendor/missing-migration-package', path: $packagePath, version: '1.0.0');

        expect(fn (): null => InstallPackageAction::run(CapellCore::getPackage('vendor/missing-migration-package')))
            ->toThrow(
                RuntimeException::class,
                'Package vendor/missing-migration-package declares migrations, but database/migrations is missing.',
            );

        $extension = CapellExtension::query()
            ->where('composer_name', 'vendor/missing-migration-package')
            ->firstOrFail();

        expect($extension->status)->toBe(ExtensionStatusEnum::Failed)
            ->and($extension->installed_at)->toBeNull()
            ->and(CapellCore::isPackageInstalled('vendor/missing-migration-package'))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('marks packages failed when declared migrations do not run successfully', function (): void {
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    $packagePath = makeInstallActionDatabasePackageFixture(
        composerName: 'vendor/failing-migration-package',
        declaresSchema: true,
        declaresSettings: false,
        writeSchemaMigration: true,
    );

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', [
        '--force' => true,
        '--path' => database_path('migrations'),
        '--realpath' => true,
    ])->andReturn(1);
    $kernel->shouldReceive('output')->andReturn('Migration failed');
    app()->instance(Kernel::class, $kernel);

    try {
        CapellCore::registerPackage('vendor/failing-migration-package', path: $packagePath, version: '1.0.0');

        expect(fn (): null => InstallPackageAction::run(CapellCore::getPackage('vendor/failing-migration-package')))
            ->toThrow(RuntimeException::class, 'Migration command exited with status 1.');

        $extension = CapellExtension::query()
            ->where('composer_name', 'vendor/failing-migration-package')
            ->firstOrFail();

        expect($extension->status)->toBe(ExtensionStatusEnum::Failed)
            ->and($extension->installed_at)->toBeNull()
            ->and(CapellCore::isPackageInstalled('vendor/failing-migration-package'))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('does not publish undeclared migration directories for packages without database work', function (): void {
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    $packagePath = makeInstallActionDatabasePackageFixture(
        composerName: 'vendor/no-database-package',
        declaresSchema: false,
        declaresSettings: false,
        writeSchemaMigration: true,
        writeSettingsMigration: true,
    );

    try {
        CapellCore::registerPackage('vendor/no-database-package', path: $packagePath, version: '1.0.0');

        InstallPackageAction::run(CapellCore::getPackage('vendor/no-database-package'));

        expect(collect($filesystem->calls)->contains(fn (array $call): bool => $call[0] === 'copy'))
            ->toBeFalse()
            ->and(CapellCore::isPackageInstalled('vendor/no-database-package'))->toBeTrue();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('completes declared migrations while installing before running an explicit lifecycle action', function (): void {
    InstallStateLifecycleRecorderAction::reset();
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    $packagePath = makeInstallActionDatabasePackageFixture(
        composerName: 'vendor/ordered-lifecycle-package',
        declaresSchema: true,
        declaresSettings: false,
        writeSchemaMigration: true,
        installAction: InstallStateLifecycleRecorderAction::class,
    );

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', [
        '--force' => true,
        '--path' => database_path('migrations'),
        '--realpath' => true,
    ])->andReturn(0);
    $kernel->shouldReceive('output')->andReturn('Package migrations applied');
    app()->instance(Kernel::class, $kernel);
    $reporter = new RecordingInstallProgressReporter;

    try {
        CapellCore::registerPackage('vendor/ordered-lifecycle-package', path: $packagePath, version: '1.0.0');

        InstallPackageAction::run(CapellCore::getPackage('vendor/ordered-lifecycle-package'), reporter: $reporter);

        $migrationOutputPosition = array_search('Package migrations applied', $reporter->lines, true);
        $lifecyclePosition = array_search('explicit lifecycle action ran', $reporter->lines, true);

        expect($migrationOutputPosition)->toBeInt()
            ->and($lifecyclePosition)->toBeInt()
            ->and($migrationOutputPosition)->toBeLessThan($lifecyclePosition)
            ->and(InstallStateLifecycleRecorderAction::$calls)->toBe(1)
            ->and(InstallStateLifecycleRecorderAction::$observedStatus)->toBe(ExtensionStatusEnum::Installing)
            ->and(InstallStateLifecycleRecorderAction::$observedInstalled)->toBeFalse()
            ->and(CapellCore::isPackageInstalled('vendor/ordered-lifecycle-package'))->toBeTrue();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

function makeInstallActionComposerPackageFixture(string $composerName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-install-action-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => $composerName], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}

/**
 * @param  class-string<ServiceProvider>  $runtimeProvider
 */
function makeInstallActionManifestPackageFixture(string $composerName, string $runtimeProvider): string
{
    $packagePath = makeInstallActionComposerPackageFixture($composerName);

    file_put_contents(
        $packagePath . '/capell.json',
        json_encode([
            'manifest-version' => 3,
            'name' => $composerName,
            'slug' => 'runtime-provider-package',
            'displayName' => 'Runtime Provider Package',
            'kind' => 'package',
            'capellApiVersion' => '^0.0',
            'version' => '0.0.x-dev',
            'description' => 'Runtime provider package.',
            'product' => [
                'group' => 'Testing',
                'tier' => 'free',
                'bundle' => 'testing',
            ],
            'surfaces' => ['frontend'],
            'dependencies' => [
                'requires' => ['capell-app/core'],
                'supports' => [],
                'conflicts' => [],
            ],
            'providers' => [
                'metadata' => [],
                'install' => [],
                'runtime' => [$runtimeProvider],
                'admin' => [],
                'frontend' => [],
            ],
            'contributes' => [],
            'database' => [
                'migrations' => false,
                'settings' => false,
                'requiredTables' => [],
            ],
            'commands' => [
                'install' => null,
                'setup' => null,
                'demo' => null,
                'doctor' => null,
            ],
            'settings' => [],
            'permissions' => [],
            'capabilities' => [],
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'adminQueryBudget' => 0,
                'cacheTags' => [],
                'cacheSafety' => [
                    'cacheable' => false,
                    'variesBy' => [],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [],
                    'queueInvalidation' => false,
                ],
            ],
            'healthChecks' => [],
            'commercial' => [
                'proposedLicense' => 'free',
                'requestedCertification' => 'first-party',
                'supportPolicy' => 'capell-first-party',
                'privateDocsRequested' => false,
            ],
            'marketplace' => [
                'summary' => 'Runtime provider package.',
                'screenshots' => [],
                'categories' => ['testing'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}

/**
 * @param  class-string|null  $installAction
 */
function makeInstallActionDatabasePackageFixture(
    string $composerName,
    bool $declaresSchema,
    bool $declaresSettings,
    bool $writeSchemaMigration = false,
    bool $writeSettingsMigration = false,
    ?string $installAction = null,
): string {
    $packagePath = makeInstallActionManifestPackageFixture(
        $composerName,
        RuntimeProviderInstallPackageFixture::class,
    );
    $manifestPath = $packagePath . '/capell.json';
    $manifestContents = File::get($manifestPath);
    /** @var array<string, mixed> $manifest */
    $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
    $manifest['database'] = [
        'migrations' => $declaresSchema,
        'settings' => $declaresSettings,
        'requiredTables' => [],
    ];
    $manifest['providers'] = [
        'metadata' => [],
        'install' => [],
        'runtime' => [],
        'admin' => [],
        'frontend' => [],
    ];
    $manifest['actions'] = $installAction === null ? [] : ['install' => $installAction];
    File::put(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $migrationToken = str($composerName)->replace(['/', '-'], '_')->toString();

    if ($writeSchemaMigration) {
        File::ensureDirectoryExists($packagePath . '/database/migrations');
        File::put(
            $packagePath . '/database/migrations/2026_07_11_000001_create_' . $migrationToken . '_table.php',
            '<?php declare(strict_types=1);',
        );
    }

    if ($writeSettingsMigration) {
        File::ensureDirectoryExists($packagePath . '/database/settings');
        File::put(
            $packagePath . '/database/settings/2026_07_11_000002_add_' . $migrationToken . '_settings.php',
            '<?php declare(strict_types=1);',
        );
    }

    return $packagePath;
}
