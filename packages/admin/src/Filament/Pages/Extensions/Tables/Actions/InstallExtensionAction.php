<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables\Actions;

use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\PackageData;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use Throwable;

final class InstallExtensionAction
{
    public static function make(): Action
    {
        return Action::make('installExtension')
            ->label(__('capell-admin::button.install_extension'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('primary')
            ->button()
            ->visible(fn (array $record): bool => ExtensionsPage::canManageExtensions()
                && ($record['installed'] ?? false) === false
                && ($record['core'] ?? false) === false
                && ExtensionRecord::canInstall($record))
            ->action(function (array $record, Component $livewire): void {
                ExtensionRecord::rememberTablePosition($record, $livewire);

                $package = ExtensionRecord::resolvePackage($record);

                if (! $package instanceof PackageData) {
                    return;
                }

                try {
                    InstallPackageAction::run($package);
                } catch (Throwable $throwable) {
                    Notification::make('extension-install-failed')
                        ->title(__('capell-admin::message.extension_install_failed', [
                            'extension' => ExtensionRecord::label($record),
                        ]))
                        ->body($throwable->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make('extension-installed')
                    ->title(__('capell-admin::message.extension_installed', [
                        'extension' => ExtensionRecord::label($record),
                    ]))
                    ->body(__('capell-admin::message.extension_installed_body'))
                    ->success()
                    ->send();

                ExtensionRecord::refreshTable($livewire);
            });
    }
}
