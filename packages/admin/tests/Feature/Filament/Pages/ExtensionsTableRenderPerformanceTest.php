<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\ResolveExtensionUninstallAvailabilityAction;
use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function renderedExtensionPackagePath(): string
{
    $path = realpath(__DIR__ . '/../../../../../../tests/fixtures/extension-package');

    throw_if($path === false, RuntimeException::class, 'Extension package fixture path could not be resolved.');

    return $path;
}

it('does not resolve uninstall dependency availability while rendering extension cards', function (): void {
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);

    $this->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:ExtensionsPage');
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);

    CapellCore::registerPackage(
        name: 'vendor/rendered-extension',
        path: renderedExtensionPackagePath(),
        version: '1.2.3',
    );
    CapellCore::markPackageInstalled('vendor/rendered-extension');

    $availabilityCalls = new stdClass;
    $availabilityCalls->count = 0;

    app()->bind(ResolveExtensionUninstallAvailabilityAction::class, fn (): object => new readonly class($availabilityCalls)
    {
        public function __construct(private stdClass $availabilityCalls) {}

        public function handle(string $packageName, ?PackageData $package = null, ?bool $installed = null): ExtensionUninstallAvailabilityData
        {
            $this->availabilityCalls->count++;

            return new ExtensionUninstallAvailabilityData(
                visible: true,
                canRun: true,
                dependentPackages: [],
                dependentPackageNames: [],
                requiredConfirmationPackageNames: [],
                uninstallPackageNames: [$packageName],
                blockReason: null,
                tooltip: 'Uninstall extension',
                modalDescription: 'Uninstall extension.',
                showRemovalModeForm: true,
                requiresDependentConfirmation: false,
            );
        }
    });

    Livewire::test(ExtensionsPage::class)
        ->assertSuccessful();

    expect($availabilityCalls->count)->toBe(0);
});
