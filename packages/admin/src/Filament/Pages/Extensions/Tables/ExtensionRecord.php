<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables;

use Capell\Admin\Actions\Extensions\ResolveExtensionUninstallAvailabilityAction;
use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Filament\Notifications\Notification;
use Livewire\Component;
use Throwable;

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
    public static function canShowUninstall(array $record): bool
    {
        return ExtensionsPage::canManageExtensions()
            && ($record['installed'] ?? false) === true;
    }

    /** @param array<string, mixed> $record */
    public static function canResolvePackage(array $record): bool
    {
        $packageName = $record['packageName'] ?? null;

        return is_string($packageName)
            && $packageName !== ''
            && CapellCore::hasPackage($packageName);
    }

    /** @param array<string, mixed> $record */
    public static function package(array $record): ?PackageData
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
    public static function uninstallAvailability(array $record): ExtensionUninstallAvailabilityData
    {
        $packageName = $record['packageName'] ?? null;

        return ResolveExtensionUninstallAvailabilityAction::run(
            packageName: is_string($packageName) ? $packageName : '',
            package: self::package($record),
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

    /** @param array<string, mixed> $record */
    public static function sendUninstallFailedNotification(array $record, Throwable $exception): void
    {
        Notification::make('extension-uninstall-failed')
            ->title(__('capell-admin::message.extension_uninstall_failed', [
                'extension' => self::label($record),
            ]))
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    /** @param array<string, mixed> $record */
    public static function uninstallPackages(ExtensionUninstallAvailabilityData $availability, array $record, bool $deletePackage, bool $deleteData): bool
    {
        foreach ($availability->uninstallPackageNames as $packageName) {
            if (! CapellCore::hasPackage($packageName)) {
                continue;
            }

            try {
                UninstallPackageAction::run(CapellCore::getPackage($packageName), delete: $deletePackage, deleteData: $deleteData);
            } catch (Throwable $exception) {
                self::sendUninstallFailedNotification(self::forPackageName($packageName, $record), $exception);

                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $fallbackRecord
     * @return array<string, mixed>
     */
    public static function forPackageName(string $packageName, array $fallbackRecord): array
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

    public static function translation(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
