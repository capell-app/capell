<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\ResolveExtensionUninstallAvailabilityAction;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CapellCore::clearExtensionCache();
});

function grantResolveExtensionUninstallAvailabilityAccess(): void
{
    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

it('resolves runnable uninstall availability for installed packages', function (): void {
    grantResolveExtensionUninstallAvailabilityAccess();

    CapellCore::registerPackage(name: 'vendor/runnable-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/runnable-extension');

    $availability = ResolveExtensionUninstallAvailabilityAction::run('vendor/runnable-extension');

    expect($availability->visible)->toBeTrue()
        ->and($availability->canRun)->toBeTrue()
        ->and($availability->dependentPackages)->toBe([])
        ->and($availability->blockReason)->toBeNull()
        ->and($availability->tooltip)->toBe(__('capell-admin::button.uninstall_extension'))
        ->and($availability->modalDescription)->toBe(__('capell-admin::generic.uninstall_extension_description'))
        ->and($availability->showRemovalModeForm)->toBeTrue();
});

it('resolves dependency confirmation requirements with dependent labels', function (): void {
    grantResolveExtensionUninstallAvailabilityAccess();

    CapellCore::registerPackage(name: 'vendor/base-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/base-extension');
    CapellCore::registerPackage(name: 'vendor/dependent-extension', version: '1.0.0');
    CapellCore::getPackage('vendor/dependent-extension')->requirements = ['vendor/base-extension'];
    CapellCore::markPackageInstalled('vendor/dependent-extension');

    $availability = ResolveExtensionUninstallAvailabilityAction::run('vendor/base-extension');

    expect($availability->visible)->toBeTrue()
        ->and($availability->canRun)->toBeTrue()
        ->and($availability->dependentPackages)->toBe(['Dependent Extension (vendor/dependent-extension)'])
        ->and($availability->dependentPackageNames)->toBe(['vendor/dependent-extension'])
        ->and($availability->requiredConfirmationPackageNames)->toBe(['vendor/dependent-extension', 'vendor/base-extension'])
        ->and($availability->uninstallPackageNames)->toBe(['vendor/dependent-extension', 'vendor/base-extension'])
        ->and($availability->blockReason)->toBe(trans_choice('capell-admin::generic.extension_uninstall_blocked_by_dependents', 1, [
            'extensions' => 'Dependent Extension (vendor/dependent-extension)',
        ]))
        ->and($availability->tooltip)->toBe(__('capell-admin::button.uninstall_extension'))
        ->and($availability->modalDescription)->toBe(trans_choice('capell-admin::generic.extension_uninstall_blocked_modal_dependents', 1, [
            'extensions' => 'Dependent Extension (vendor/dependent-extension)',
        ]))
        ->and($availability->showRemovalModeForm)->toBeTrue()
        ->and($availability->requiresDependentConfirmation)->toBeTrue();
});

it('resolves registry-missing installed records as unavailable close-only actions', function (): void {
    grantResolveExtensionUninstallAvailabilityAccess();

    CapellExtension::query()->create([
        'composer_name' => 'vendor/missing-extension',
        'name' => 'Missing Extension',
        'status' => ExtensionStatusEnum::Enabled,
        'installed_at' => now(),
    ]);

    $availability = ResolveExtensionUninstallAvailabilityAction::run('vendor/missing-extension');

    expect($availability->visible)->toBeTrue()
        ->and($availability->canRun)->toBeFalse()
        ->and($availability->dependentPackages)->toBe([])
        ->and($availability->blockReason)->toBe(__('capell-admin::generic.extension_uninstall_blocked_package_unavailable'))
        ->and($availability->tooltip)->toBe(__('capell-admin::generic.extension_uninstall_blocked_package_unavailable'))
        ->and($availability->modalDescription)->toBe(__('capell-admin::generic.extension_uninstall_blocked_package_unavailable'))
        ->and($availability->showRemovalModeForm)->toBeFalse();
});

it('hides uninstall availability for uninstalled packages', function (): void {
    grantResolveExtensionUninstallAvailabilityAccess();

    CapellCore::registerPackage(name: 'vendor/uninstalled-extension', version: '1.0.0');

    $availability = ResolveExtensionUninstallAvailabilityAction::run('vendor/uninstalled-extension');

    expect($availability->visible)->toBeFalse()
        ->and($availability->canRun)->toBeFalse()
        ->and($availability->showRemovalModeForm)->toBeFalse();
});

it('hides uninstall availability without management permission', function (): void {
    test()->actingAsUser();

    CapellCore::registerPackage(name: 'vendor/managed-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/managed-extension');

    $availability = ResolveExtensionUninstallAvailabilityAction::run('vendor/managed-extension');

    expect($availability->visible)->toBeFalse()
        ->and($availability->canRun)->toBeFalse()
        ->and($availability->showRemovalModeForm)->toBeFalse();
});
