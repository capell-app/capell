<?php

declare(strict_types=1);

use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Contracts\Extensions\DeletesExtensionData;
use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

beforeEach(function (): void {
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();
});

it('uninstalls a package', function (): void {
    File::spy();

    CapellCore::registerPackage('vendor/package', PackageTypeEnum::Plugin, version: '^1.0');
    CapellCore::forcePackageInstalled('vendor/package');

    $package = new PackageData(
        name: 'vendor/package',
        type: PackageTypeEnum::Plugin,
        version: '^1.0',
        installed: true,
    );

    UninstallPackageAction::run($package);

    expect(CapellCore::isPackageInstalled('vendor/package'))->toBeFalse();
});

it('deletes installed extension metadata when a package is uninstalled', function (): void {
    CapellCore::registerPackage('vendor/tracked-package', PackageTypeEnum::Plugin, version: '1.2.3');
    CapellCore::markPackageInstalled('vendor/tracked-package');

    expect(CapellExtension::query()->where('composer_name', 'vendor/tracked-package')->exists())->toBeTrue();

    UninstallPackageAction::run(CapellCore::getPackage('vendor/tracked-package'));

    expect(CapellExtension::query()->where('composer_name', 'vendor/tracked-package')->exists())->toBeFalse();
});

it('deletes published package migrations when a package is uninstalled', function (): void {
    $packagePath = makeUninstallPackageWithMigrationFixture('vendor/migration-package');
    $sourceMigration = $packagePath . '/database/migrations/2026_05_10_190832_01_create_migration_package_table.php';
    $publishedMigration = database_path('migrations/2026_05_10_190832_01_create_migration_package_table.php');

    $filesystem = new FakeMigrationFilesystem([
        'glob' => [
            $packagePath . '/database/migrations/*.php' => [$sourceMigration],
            $packagePath . '/database/migrations/*.php.stub' => [],
        ],
        'fileExists' => [
            $publishedMigration => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    CapellCore::registerPackage('vendor/migration-package', PackageTypeEnum::Plugin, path: $packagePath, version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/migration-package');

    UninstallPackageAction::run(CapellCore::getPackage('vendor/migration-package'));

    expect($filesystem->calls)->toContain(['delete', $publishedMigration]);
});

it('keeps extension data by default so reinstall can reuse it', function (): void {
    UninstallPackageActionDataDeleter::$deletedPackages = [];

    CapellCore::registerPackage(
        name: 'vendor/data-package',
        serviceProviderClass: UninstallPackageActionDataDeleter::class,
        version: '1.0.0',
    );
    CapellCore::markPackageInstalled('vendor/data-package');

    UninstallPackageAction::run(CapellCore::getPackage('vendor/data-package'));

    expect(UninstallPackageActionDataDeleter::$deletedPackages)->toBe([]);
});

it('allows uninstall to delete extension-owned data when requested', function (): void {
    UninstallPackageActionDataDeleter::$deletedPackages = [];

    CapellCore::registerPackage(
        name: 'vendor/data-package',
        serviceProviderClass: UninstallPackageActionDataDeleter::class,
        version: '1.0.0',
    );
    CapellCore::markPackageInstalled('vendor/data-package');

    UninstallPackageAction::run(CapellCore::getPackage('vendor/data-package'), deleteData: true);

    expect(UninstallPackageActionDataDeleter::$deletedPackages)->toBe(['vendor/data-package']);
});

it('runs a declared uninstall lifecycle action before marking the package uninstalled', function (): void {
    UninstallPackageLifecycleAction::$packages = [];
    CapellCore::registerPackage('vendor/lifecycle-package', PackageTypeEnum::Plugin, version: '1.0.0');
    $package = CapellCore::getPackage('vendor/lifecycle-package');
    $package->uninstallAction = UninstallPackageLifecycleAction::class;
    CapellCore::markPackageInstalled($package->name);

    UninstallPackageAction::run($package);

    expect(UninstallPackageLifecycleAction::$packages)->toBe([
        ['vendor/lifecycle-package', [], true],
    ]);
});

it('blocks uninstalling the active theme package', function (): void {
    $settings = resolve(ThemeStudioSettings::class);
    $settings->activeTheme = 'editorial';

    CapellCore::registerPackage('capell-app/theme-editorial', PackageTypeEnum::Theme, version: '1.0.0');
    $package = CapellCore::getPackage('capell-app/theme-editorial');
    $package->themeKey = 'editorial';
    CapellCore::markPackageInstalled($package->name);

    expect(fn (): null => UninstallPackageAction::run($package))
        ->toThrow(Exception::class, "cannot be uninstalled while theme 'editorial' is in use");

    expect(CapellCore::isPackageInstalled($package->name))->toBeTrue();
});

it('blocks uninstalling a theme selected by a site', function (): void {
    $package = installedThemePackageForUninstall('editorial');
    $theme = Theme::factory()->createOne(['key' => 'editorial']);
    Site::factory()->theme($theme)->createOne();

    expect(fn (): null => UninstallPackageAction::run($package))
        ->toThrow(Exception::class, '1 site(s), 0 layout(s), global active theme: no');

    expect(CapellCore::isPackageInstalled($package->name))->toBeTrue();
});

it('blocks uninstalling a theme selected by a layout', function (): void {
    $package = installedThemePackageForUninstall('editorial');
    $theme = Theme::factory()->createOne(['key' => 'editorial']);
    Layout::factory()->createOne(['theme_id' => $theme->getKey()]);

    expect(fn (): null => UninstallPackageAction::run($package))
        ->toThrow(Exception::class, '0 site(s), 1 layout(s), global active theme: no');

    expect(CapellCore::isPackageInstalled($package->name))->toBeTrue();
});

it('allows uninstalling an unused theme', function (): void {
    $package = installedThemePackageForUninstall('editorial');
    Theme::factory()->createOne(['key' => 'editorial']);

    UninstallPackageAction::run($package);

    expect(CapellCore::isPackageInstalled($package->name))->toBeFalse();
});

it('uninstalls trusted packages through the extension lifecycle', function (): void {
    $packagePath = makeUninstallComposerPackageFixture('capell-app/installer');

    CapellCore::registerPackage(
        name: 'capell-app/installer',
        path: $packagePath,
        version: '1.2.3',
    );

    UninstallPackageAction::run(CapellCore::getPackage('capell-app/installer'));
    CapellCore::removeCacheKey(CacheEnum::ExtensionUninstalledNames->value);

    expect(CapellExtension::query()->where('composer_name', 'capell-app/installer')->value('status'))->toBe(ExtensionStatusEnum::Uninstalled)
        ->and(CapellCore::isPackageInstalled('capell-app/installer'))->toBeFalse();
});

it('blocks trusted package uninstall when installed dependents exist', function (): void {
    $packagePath = makeUninstallComposerPackageFixture('capell-app/installer');

    CapellCore::registerPackage(
        name: 'capell-app/installer',
        path: $packagePath,
        version: '1.2.3',
    );
    CapellCore::registerPackage('vendor/installer-dependent', PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::getPackage('vendor/installer-dependent')->requirements = ['capell-app/installer'];
    CapellCore::markPackageInstalled('vendor/installer-dependent');

    expect(fn (): null => UninstallPackageAction::run(CapellCore::getPackage('capell-app/installer')))
        ->toThrow(Exception::class, 'cannot be uninstalled because the following installed plugin(s) depend on it: vendor/installer-dependent.');
});

it('can disable a package without uninstalling it', function (): void {
    $composerName = 'vendor/disabled-package-' . bin2hex(random_bytes(4));
    $packagePath = makeUninstallComposerPackageFixture($composerName);

    try {
        CapellCore::registerPackage($composerName, path: $packagePath);
        CapellCore::markPackageInstalled($composerName);

        DisablePackageAction::run(CapellCore::getPackage($composerName));

        $extension = CapellExtension::query()
            ->where('composer_name', $composerName)
            ->first();
        $extension = expectPresent($extension);

        expect($extension)->not->toBeNull()
            ->and($extension->status)->toBe(ExtensionStatusEnum::Disabled)
            ->and(CapellCore::isPackageEnabled($composerName))->toBeFalse()
            ->and(CapellCore::isPackageAvailable($composerName))->toBeTrue();
    } finally {
        if (is_file($packagePath . '/composer.json')) {
            unlink($packagePath . '/composer.json');
        }

        if (is_dir($packagePath)) {
            rmdir($packagePath);
        }
    }
});

it('keeps a composer-discovered package uninstalled after extension cache refreshes', function (): void {
    $composerName = 'capell-app/agent-bridge-characterization-' . bin2hex(random_bytes(4));
    $packagePath = makeUninstallComposerPackageFixture($composerName);

    CapellCore::registerPackage(
        name: $composerName,
        path: $packagePath,
        version: '1.0.0',
    );
    CapellCore::markPackageInstalled($composerName);

    expect(CapellCore::isPackageInstalled($composerName))->toBeTrue();

    UninstallPackageAction::run(CapellCore::getPackage($composerName));
    CapellCore::clearExtensionCache();

    expect(CapellCore::isPackageInstalled($composerName))->toBeFalse();
});

it('deletes a package through composer remove when requested', function (): void {
    UninstallPackageActionDataDeleter::$deletedPackages = [];

    CapellCore::registerPackage('vendor/package', PackageTypeEnum::Plugin, version: '^1.0');
    /** @phpstan-ignore-next-line This test exercises the delete-data lifecycle contract, not provider booting. */
    CapellCore::getPackage('vendor/package')->serviceProviderClass = UninstallPackageActionDataDeleter::class;
    CapellCore::forcePackageInstalled('vendor/package');
    bindSuccessfulComposerRemoveProcess('vendor/package');

    UninstallPackageAction::run(CapellCore::getPackage('vendor/package'), delete: true);

    expect(CapellCore::isPackageInstalled('vendor/package'))->toBeFalse()
        ->and(UninstallPackageActionDataDeleter::$deletedPackages)->toBe(['vendor/package']);
});

it('completes Capell lifecycle cleanup before deleting package files through Composer', function (): void {
    $packagePath = makeUninstallPackageWithMigrationFixture('vendor/package-lifecycle-order');
    $sourceMigration = $packagePath . '/database/migrations/2026_05_10_190832_01_create_migration_package_table.php';
    $publishedMigration = database_path('migrations/2026_05_10_190832_01_create_migration_package_table.php');

    $filesystem = new FakeMigrationFilesystem([
        'glob' => [
            $packagePath . '/database/migrations/*.php' => [$sourceMigration],
            $packagePath . '/database/migrations/*.php.stub' => [],
        ],
        'fileExists' => [
            $publishedMigration => true,
        ],
    ]);

    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    CapellCore::registerPackage('vendor/package-lifecycle-order', PackageTypeEnum::Plugin, path: $packagePath, version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/package-lifecycle-order');
    bindSuccessfulComposerRemoveProcess('vendor/package-lifecycle-order', function () use ($filesystem, $publishedMigration): void {
        expect(CapellExtension::query()->where('composer_name', 'vendor/package-lifecycle-order')->exists())->toBeFalse()
            ->and(CapellCore::isPackageInstalled('vendor/package-lifecycle-order'))->toBeFalse()
            ->and($filesystem->calls)->toContain(['delete', $publishedMigration]);
    });

    UninstallPackageAction::run(CapellCore::getPackage('vendor/package-lifecycle-order'), delete: true);
});

it('handles uninstall failures', function (): void {
    CapellCore::registerPackage('invalid/package', PackageTypeEnum::Plugin, version: '^0.0');
    // Not installed, so uninstall should fail
    $package = new PackageData(
        name: 'invalid/package',
        type: PackageTypeEnum::Plugin,
        version: '^0.0',
        installed: false,
    );

    expect(fn () => UninstallPackageAction::run($package))
        ->toThrow(Exception::class, 'is not installed');
});

it('fails if dependents exist', function (): void {
    // Register and install a package and a dependent
    CapellCore::registerPackage('vendor/package', PackageTypeEnum::Plugin, version: '^1.0');
    CapellCore::registerPackage('dependent/package', PackageTypeEnum::Plugin, path: realpath(__DIR__ . '/../../../../../tests/fixtures/dependent-package'), version: '^1.0');
    CapellCore::forcePackageInstalled('vendor/package');
    CapellCore::forcePackageInstalled('dependent/package');

    $package = new PackageData(
        name: 'vendor/package',
        type: PackageTypeEnum::Plugin,
        version: '^1.0',
        installed: true,
    );

    expect(fn () => UninstallPackageAction::run($package))
        ->toThrow(Exception::class, 'cannot be uninstalled because the following installed plugin(s) depend on it: dependent/package.');
});

function makeUninstallComposerPackageFixture(string $composerName): string
{
    $packagePath = sys_get_temp_dir() . '/capell-uninstall-composer-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => $composerName], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    return $packagePath;
}

function installedThemePackageForUninstall(string $themeKey): PackageData
{
    $composerName = 'capell-app/theme-' . $themeKey;
    CapellCore::registerPackage($composerName, PackageTypeEnum::Theme, version: '1.0.0');
    $package = CapellCore::getPackage($composerName);
    $package->themeKey = $themeKey;
    CapellCore::markPackageInstalled($package->name);

    return $package;
}

function makeUninstallPackageWithMigrationFixture(string $composerName): string
{
    $packagePath = makeUninstallComposerPackageFixture($composerName);

    File::ensureDirectoryExists($packagePath . '/database/migrations');
    File::put(
        $packagePath . '/database/migrations/2026_05_10_190832_01_create_migration_package_table.php',
        '<?php',
    );

    return $packagePath;
}

function bindSuccessfulComposerRemoveProcess(string $packageName, ?Closure $beforeRun = null): void
{
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();

    $process = Mockery::mock(Process::class);

    $process
        ->shouldReceive('setEnv')
        ->with(Mockery::type('array'))
        ->andReturnSelf();

    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();

    $process
        ->shouldReceive('run')
        ->andReturnUsing(function () use ($beforeRun): int {
            $beforeRun?->__invoke();

            return 0;
        });

    $process
        ->shouldReceive('getErrorOutput')
        ->andReturn('');

    $process
        ->shouldReceive('getOutput')
        ->andReturn(sprintf('Package %s removed', $packageName));

    $process
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);

    $factory
        ->shouldReceive('make')
        ->with(['composer', 'remove', $packageName, '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

final class UninstallPackageActionDataDeleter implements DeletesExtensionData
{
    /** @var list<string> */
    public static array $deletedPackages = [];

    public static function compatibleCapellApiVersion(): string
    {
        return '1.0';
    }

    public function deleteExtensionData(PackageData $package): void
    {
        self::$deletedPackages[] = $package->name;
    }
}

final class UninstallPackageLifecycleAction implements PackageLifecycleAction
{
    /** @var list<array{string, array<string, mixed>, bool}> */
    public static array $packages = [];

    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        self::$packages[] = [$package->name, $arguments, CapellCore::isPackageInstalled($package->name)];
    }
}
