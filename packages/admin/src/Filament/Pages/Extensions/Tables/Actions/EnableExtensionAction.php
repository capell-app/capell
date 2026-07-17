<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables\Actions;

use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Data\PackageData;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class EnableExtensionAction
{
    public static function make(): Action
    {
        return Action::make('enableExtension')
            ->label(__('capell-admin::button.enable_extension'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->button()
            ->visible(fn (array $record): bool => ExtensionsPage::canManageExtensions()
                && ($record['installed'] ?? false) === true
                && ($record['core'] ?? false) === false
                && ($record['enabled'] ?? false) === false)
            ->action(function (array $record, Component $livewire): void {
                ExtensionRecord::rememberTablePosition($record, $livewire);

                $package = ExtensionRecord::resolvePackage($record);

                if (! $package instanceof PackageData) {
                    return;
                }

                EnablePackageAction::run($package);

                Notification::make('extension-enabled')
                    ->title(__('capell-admin::message.extension_enabled'))
                    ->body(__('capell-admin::message.extension_enabled_body'))
                    ->success()
                    ->send();

                ExtensionRecord::refreshTable($livewire);
            });
    }
}
