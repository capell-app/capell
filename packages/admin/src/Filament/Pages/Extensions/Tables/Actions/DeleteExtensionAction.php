<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables\Actions;

use Capell\Admin\Actions\Extensions\UninstallExtensionPackagesAction;
use Capell\Admin\Data\Extensions\ExtensionPackageUninstallResultData;
use Capell\Admin\Data\Extensions\ExtensionRemovalRequestData;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
use Capell\Admin\Support\Extensions\ExtensionRemovalRouter;
use Capell\Core\Actions\DeleteExtensionDataAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Data\PackageData;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use Throwable;

final class DeleteExtensionAction
{
    public static function make(): Action
    {
        return Action::make('deleteExtension')
            ->label(__('capell-admin::button.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->extraAttributes(['class' => 'capell-extension-card-lifecycle-action'])
            ->button()
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('capell-admin::generic.delete_extension_heading', [
                'extension' => (string) ($record['label'] ?? $record['packageName'] ?? ''),
            ]))
            ->modalDescription(__('capell-admin::generic.delete_extension_description'))
            ->modalSubmitActionLabel(__('capell-admin::button.delete'))
            ->visible(fn (array $record): bool => ExtensionRecord::canDelete($record))
            ->action(function (array $record, Component $livewire): void {
                ExtensionRecord::rememberTablePosition($record, $livewire);

                $package = ExtensionRecord::resolvePackage($record);

                if (! $package instanceof PackageData) {
                    return;
                }

                $installed = ($record['installed'] ?? false) === true;
                $availability = ExtensionRecord::resolveUninstallAvailability($record);

                if ($installed && ! $availability->canRun) {
                    return;
                }

                // Asked before anything is torn down, because the queued and
                // manual answers replace this whole method rather than a step
                // inside it.
                if (! ExtensionRemovalRouter::shouldRemoveInRequest(
                    new ExtensionRemovalRequestData(
                        composerName: $package->name,
                        packageNames: $installed ? $availability->uninstallPackageNames : [$package->name],
                        deletePackage: true,
                        deleteData: true,
                        extensionSlug: (string) ($record['slug'] ?? ''),
                        extensionName: ExtensionRecord::label($record),
                        kind: (string) ($record['kind'] ?? 'plugin'),
                        runLifecycle: $installed,
                    ),
                    ExtensionRecord::label($record),
                )) {
                    return;
                }

                if ($installed) {
                    $uninstallResult = UninstallExtensionPackagesAction::run(
                        $availability->uninstallPackageNames,
                        deletePackage: false,
                        deleteData: true,
                        requiresServerSideTooling: true,
                    );

                    if (! $uninstallResult->successful) {
                        self::sendUninstallFailedNotification($uninstallResult, $record);

                        return;
                    }
                } else {
                    DeleteExtensionDataAction::run($package);
                }

                try {
                    // Unattended Composer write driven by an HTTP request, so it
                    // is gated by CAPELL_SERVER_SIDE_TOOLING exactly as a
                    // web-triggered install is.
                    RemovePackageAction::run($package->name, requiresServerSideTooling: true);
                } catch (Throwable $throwable) {
                    self::sendUninstallFailedNotification(
                        ExtensionPackageUninstallResultData::failed(
                            packageName: $package->name,
                            failureMessage: $throwable->getMessage(),
                            uninstalledPackageNames: [],
                        ),
                        $record,
                    );

                    return;
                }

                Notification::make('extension-deleted')
                    ->title(__('capell-admin::message.extension_deleted', [
                        'extension' => ExtensionRecord::label($record),
                    ]))
                    ->body(__('capell-admin::message.extension_deleted_body'))
                    ->success()
                    ->send();

                ExtensionRecord::refreshTable($livewire);
            });
    }

    /** @param array<string, mixed> $record */
    private static function sendUninstallFailedNotification(ExtensionPackageUninstallResultData $result, array $record): void
    {
        $failedRecord = $result->failedPackageName === null
            ? $record
            : ExtensionRecord::recordForPackageName($result->failedPackageName, $record);

        Notification::make('extension-uninstall-failed')
            ->title(__('capell-admin::message.extension_uninstall_failed', [
                'extension' => ExtensionRecord::label($failedRecord),
            ]))
            ->body($result->failureMessage ?? '')
            ->danger()
            ->send();
    }
}
