<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables;

use Capell\Admin\Actions\Extensions\ResolveExtensionUninstallAvailabilityAction;
use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Livewire\Component;

final class ExtensionRecord
{
    /** @param array<string, mixed> $record */
    public static function canInstall(array $record): bool
    {
        $packageName = $record['packageName'] ?? null;

        return is_string($packageName)
            && $packageName !== ''
            && CapellCore::canInstallPackage($packageName);
    }

    /** @param array<string, mixed> $record */
    public static function canDelete(array $record): bool
    {
        $packageName = $record['packageName'] ?? null;

        return ExtensionsPage::canManageExtensions()
            && is_string($packageName)
            && $packageName !== ''
            && ($record['installed'] ?? false) === false
            && ($record['core'] ?? false) === false
            && CapellCore::hasPackage($packageName);
    }

    /** @param array<string, mixed> $record */
    public static function canShowUninstallAction(array $record): bool
    {
        return ExtensionsPage::canManageExtensions()
            && ($record['installed'] ?? false) === true;
    }

    /** @param array<string, mixed> $record */
    public static function hasAvailablePackage(array $record): bool
    {
        $packageName = $record['packageName'] ?? null;

        return is_string($packageName)
            && $packageName !== ''
            && CapellCore::hasPackage($packageName);
    }

    /** @param array<string, mixed> $record */
    public static function resolvePackage(array $record): ?PackageData
    {
        $packageName = $record['packageName'] ?? null;

        if (! is_string($packageName) || ! CapellCore::hasPackage($packageName)) {
            return null;
        }

        return CapellCore::getPackage($packageName);
    }

    /** @param array<string, mixed> $record */
    public static function label(array $record): string
    {
        $label = $record['label'] ?? $record['packageName'] ?? null;

        return is_string($label) && $label !== ''
            ? $label
            : __('capell-admin::generic.unknown');
    }

    /** @param array<string, mixed> $record */
    public static function resolveUninstallAvailability(array $record): ExtensionUninstallAvailabilityData
    {
        $packageName = $record['packageName'] ?? null;

        return ResolveExtensionUninstallAvailabilityAction::run(
            packageName: is_string($packageName) ? $packageName : '',
            package: self::resolvePackage($record),
            installed: ($record['installed'] ?? false) === true,
        );
    }

    /** @param array<string, mixed> $record */
    public static function rememberTablePosition(array $record, Component $livewire): void
    {
        $packageName = $record['packageName'] ?? null;

        if (! is_string($packageName) || ! method_exists($livewire, 'rememberCurrentExtensionTablePosition')) {
            return;
        }

        $livewire->rememberCurrentExtensionTablePosition($packageName);
    }

    public static function refreshTable(Component $livewire): void
    {
        if (method_exists($livewire, 'refreshExtensionOperations')) {
            $livewire->refreshExtensionOperations();
        }

        resolve(CapellAdminPlugin::class)->synchronizeCurrentPanelAdminSurface();

        $livewire->dispatch('refresh-sidebar');
    }

    /**
     * @param  array<string, mixed>  $fallbackRecord
     * @return array<string, mixed>
     */
    public static function recordForPackageName(string $packageName, array $fallbackRecord): array
    {
        if (($fallbackRecord['packageName'] ?? null) === $packageName) {
            return $fallbackRecord;
        }

        $package = CapellCore::hasPackage($packageName) ? CapellCore::getPackage($packageName) : null;

        return [
            'packageName' => $packageName,
            'label' => $package instanceof PackageData ? $package->getShortName() : $packageName,
        ];
    }
}
