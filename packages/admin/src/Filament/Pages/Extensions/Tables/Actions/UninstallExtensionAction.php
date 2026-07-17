<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages\Extensions\Tables\Actions;

use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class UninstallExtensionAction
{
    public static function make(): Action
    {
        return Action::make('uninstallExtension')
            ->label(__('capell-admin::button.uninstall_extension'))
            ->tooltip(fn (array $record): ?string => ExtensionRecord::canResolvePackage($record)
                ? null
                : ExtensionRecord::translation('capell-admin::generic.extension_uninstall_blocked_package_unavailable'))
            ->icon(Heroicon::OutlinedTrash)
            ->color(fn (array $record): string => ExtensionRecord::canResolvePackage($record) ? 'danger' : 'gray')
            ->outlined(fn (array $record): bool => ! ExtensionRecord::canResolvePackage($record))
            ->extraAttributes(['class' => 'capell-extension-card-lifecycle-action'])
            ->button()
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('capell-admin::generic.uninstall_extension_heading', [
                'extension' => (string) ($record['label'] ?? $record['packageName'] ?? ''),
            ]))
            ->modalDescription(fn (array $record): string => ExtensionRecord::uninstallAvailability($record)->modalDescription)
            ->modalSubmitAction(fn (array $record, Action $action): Action|bool => ExtensionRecord::uninstallAvailability($record)->canRun ? $action : false)
            ->modalSubmitActionLabel(fn (array $record): string => ExtensionRecord::uninstallAvailability($record)->requiresDependentConfirmation
                ? __('capell-admin::button.uninstall_confirmed_extensions')
                : __('capell-admin::button.uninstall_extension'))
            ->modalCancelActionLabel(fn (array $record): string => ExtensionRecord::uninstallAvailability($record)->canRun
                ? __('filament-actions::modal.actions.cancel.label')
                : __('capell-admin::button.close'))
            ->schema(fn (array $record): array => self::schema($record))
            ->visible(fn (array $record): bool => ExtensionRecord::canShowUninstall($record))
            ->action(function (array $record, array $data, Component $livewire): void {
                ExtensionRecord::rememberTablePosition($record, $livewire);

                $availability = ExtensionRecord::uninstallAvailability($record);

                if (! $availability->canRun) {
                    return;
                }

                if ($availability->requiresDependentConfirmation && ! self::confirmedPackageNames($availability->requiredConfirmationPackageNames, $data)) {
                    Notification::make('extension-uninstall-confirmation-required')
                        ->title(__('capell-admin::message.extension_uninstall_confirmation_required'))
                        ->body(__('capell-admin::message.extension_uninstall_confirmation_required_body'))
                        ->warning()
                        ->send();

                    return;
                }

                $deletePackage = self::shouldDeletePackage($data);

                if (! ExtensionRecord::uninstallPackages(
                    $availability,
                    $record,
                    deletePackage: $deletePackage,
                    deleteData: self::shouldDeleteData($data),
                )) {
                    return;
                }

                Notification::make('extension-uninstalled')
                    ->title($deletePackage
                        ? __('capell-admin::message.extension_deleted', ['extension' => ExtensionRecord::label($record)])
                        : self::successMessage($availability, $record))
                    ->body($deletePackage
                        ? __('capell-admin::message.extension_deleted_body')
                        : self::successBody($availability, $record))
                    ->success()
                    ->send();

                ExtensionRecord::refreshTable($livewire);
            });
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, mixed>
     */
    private static function schema(array $record): array
    {
        $availability = ExtensionRecord::uninstallAvailability($record);

        if (! $availability->requiresDependentConfirmation) {
            return [self::deletePackageCheckbox(), self::deleteDataCheckbox()];
        }

        return [
            self::dependentConfirmationCheckbox($record, $availability->requiredConfirmationPackageNames),
            self::deletePackageCheckbox(),
            self::deleteDataCheckbox(),
        ];
    }

    private static function deletePackageCheckbox(): Checkbox
    {
        return Checkbox::make('delete_extension_package')
            ->label(__('capell-admin::generic.delete_extension_package'))
            ->helperText(__('capell-admin::generic.delete_extension_package_help'))
            ->default(false);
    }

    private static function deleteDataCheckbox(): Checkbox
    {
        return Checkbox::make('delete_extension_data')
            ->label(__('capell-admin::generic.delete_extension_data'))
            ->helperText(__('capell-admin::generic.delete_extension_data_help'))
            ->default(false);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $packageNames
     */
    private static function dependentConfirmationCheckbox(array $record, array $packageNames): CheckboxList
    {
        return CheckboxList::make('confirmed_package_names')
            ->label(__('capell-admin::generic.extension_uninstall_confirm_packages_label'))
            ->helperText(__('capell-admin::generic.extension_uninstall_confirm_packages_help'))
            ->options(collect($packageNames)
                ->mapWithKeys(fn (string $packageName): array => [$packageName => ExtensionRecord::label(ExtensionRecord::forPackageName($packageName, $record)) . ' (' . $packageName . ')'])
                ->all())
            ->columns(1);
    }

    /**
     * @param  list<string>  $requiredPackageNames
     * @param  array<string, mixed>  $data
     */
    private static function confirmedPackageNames(array $requiredPackageNames, array $data): bool
    {
        $confirmedPackageNames = array_values(array_filter(
            is_array($data['confirmed_package_names'] ?? null) ? $data['confirmed_package_names'] : [],
            is_string(...),
        ));

        sort($requiredPackageNames);
        sort($confirmedPackageNames);

        return $requiredPackageNames === $confirmedPackageNames;
    }

    /** @param array<string, mixed> $record */
    private static function successMessage(ExtensionUninstallAvailabilityData $availability, array $record): string
    {
        if ($availability->requiresDependentConfirmation) {
            return __('capell-admin::message.extensions_uninstalled');
        }

        return __('capell-admin::message.extension_uninstalled', [
            'extension' => ExtensionRecord::label($record),
        ]);
    }

    /** @param array<string, mixed> $record */
    private static function successBody(ExtensionUninstallAvailabilityData $availability, array $record): string
    {
        if ($availability->requiresDependentConfirmation) {
            return __('capell-admin::message.extensions_uninstalled_body', [
                'extensions' => collect($availability->uninstallPackageNames)
                    ->map(fn (string $packageName): string => ExtensionRecord::label(ExtensionRecord::forPackageName($packageName, $record)))
                    ->implode(', '),
            ]);
        }

        return __('capell-admin::message.extension_uninstalled_body');
    }

    /** @param array<string, mixed> $data */
    private static function shouldDeletePackage(array $data): bool
    {
        return ($data['delete_extension_package'] ?? false) === true;
    }

    /** @param array<string, mixed> $data */
    private static function shouldDeleteData(array $data): bool
    {
        return ($data['delete_extension_data'] ?? false) === true;
    }
}
