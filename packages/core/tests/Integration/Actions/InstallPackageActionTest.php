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
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\RuntimeProviderInstallPackageFixture;
use Illuminate\Console\Command;
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
    CapellCore::registerPackage('vendor/member-one', version: '^4.1');
    CapellCore::registerPackage('vendor/member-two', version: '^4.1');
    CapellCore::registerPackage('vendor/showcase', version: '^4.1');
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
    CapellCore::registerPackage('vendor/member-good', version: '^4.1');
    CapellCore::registerPackage('vendor/member-broken', version: '^4.1');
    CapellCore::getPackage('vendor/member-broken')->requirements = ['vendor/missing'];
    CapellCore::registerPackage('vendor/failing-showcase', version: '^4.1');
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
            'capellApiVersion' => '^4.0',
            'version' => '4.x-dev',
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
