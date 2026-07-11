<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\DeletesExtensionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    CapellCore::clearPackages();
    UninstallExtensionCommandDataDeleter::$deletedPackages = [];
});

afterEach(function (): void {
    CapellCore::clearPackages();
});

it('uninstalls one named extension without deleting extension data by default', function (): void {
    CapellCore::registerPackage(
        name: 'vendor/example-extension',
        serviceProviderClass: UninstallExtensionCommandDataDeleter::class,
    );
    CapellCore::forcePackageInstalled('vendor/example-extension');

    artisanCommand('capell:extension-uninstall', [
        'extension' => 'vendor/example-extension',
    ])->assertSuccessful();

    expect(CapellCore::isPackageInstalled('vendor/example-extension'))->toBeFalse()
        ->and(UninstallExtensionCommandDataDeleter::$deletedPackages)->toBe([]);
});

it('refreshes cached filament menu components after uninstalling an extension', function (): void {
    $cachedPanelPath = base_path('bootstrap/cache/filament/panels/admin.php');

    File::ensureDirectoryExists(dirname($cachedPanelPath));
    File::put($cachedPanelPath, '<?php return ["resources" => ["Vendor\\\\Example\\\\AdminResource"]];');

    CapellCore::registerPackage('vendor/example-extension');
    CapellCore::forcePackageInstalled('vendor/example-extension');

    artisanCommand('capell:extension-uninstall', [
        'extension' => 'vendor/example-extension',
    ])->assertSuccessful();

    expect(File::exists($cachedPanelPath))->toBeFalse();
});

it('can delete extension data during command uninstall', function (): void {
    CapellCore::registerPackage(
        name: 'vendor/example-extension',
        serviceProviderClass: UninstallExtensionCommandDataDeleter::class,
    );
    CapellCore::forcePackageInstalled('vendor/example-extension');

    artisanCommand('capell:extension-uninstall', [
        'extension' => 'vendor/example-extension',
        '--delete-data' => true,
    ])->assertSuccessful();

    expect(UninstallExtensionCommandDataDeleter::$deletedPackages)->toBe(['vendor/example-extension']);
});

it('rejects default non-interactive runs without an extension or all flag', function (): void {
    CapellCore::registerPackage('vendor/example-extension');
    CapellCore::forcePackageInstalled('vendor/example-extension');

    $exitCode = Artisan::call('capell:extension-uninstall', [
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Pass an extension package name or use --all.');
});

it('can dry run package deletion and data deletion', function (): void {
    CapellCore::registerPackage('vendor/example-extension');
    CapellCore::forcePackageInstalled('vendor/example-extension');

    artisanCommand('capell:extension-uninstall', [
        'extension' => 'vendor/example-extension',
        '--delete-package' => true,
        '--dry-run' => true,
    ])
        ->expectsOutput('Would uninstall vendor/example-extension and delete extension data and remove the Composer package')
        ->assertSuccessful();
});

final class UninstallExtensionCommandDataDeleter implements DeletesExtensionData
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
