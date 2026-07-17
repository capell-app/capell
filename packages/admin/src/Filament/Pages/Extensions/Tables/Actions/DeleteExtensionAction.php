<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables\Actions;

use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
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

                $package = ExtensionRecord::package($record);

                if (! $package instanceof PackageData) {
                    return;
                }

                if (($record['installed'] ?? false) === true) {
                    $availability = ExtensionRecord::uninstallAvailability($record);

                    if (! $availability->canRun || ! ExtensionRecord::uninstallPackages($availability, $record, deletePackage: false, deleteData: true)) {
                        return;
                    }
                } else {
                    DeleteExtensionDataAction::run($package);
                }

                try {
                    RemovePackageAction::run($package->name);
                } catch (Throwable $throwable) {
                    ExtensionRecord::sendUninstallFailedNotification($record, $throwable);

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
}
