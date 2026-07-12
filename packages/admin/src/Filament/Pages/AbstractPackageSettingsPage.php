<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Actions\BuildSettingsSchemaComponentsAction;
use Capell\Admin\Actions\Extensions\ResolveExtensionUninstallAvailabilityAction;
use Capell\Admin\Actions\PersistMissingSettingsDefaultsAction;
use Capell\Admin\Data\Extensions\ExtensionUninstallAvailabilityData;
use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;
use RuntimeException;
use Throwable;
use UnitEnum;

abstract class AbstractPackageSettingsPage extends AbstractAdminSettingsPage
{
    protected static string $settings = CoreSettings::class;

    protected static string $settingsGroup;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return static::settingsMetadata()?->getLabel() ?? str(static::settingsGroup())->headline()->toString();
    }

    #[Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::settingsMetadata()?->getNavigationGroup();
    }

    #[Override]
    public static function getNavigationSort(): ?int
    {
        $metadata = static::settingsMetadata();

        return $metadata instanceof SettingsGroupMetadata ? $metadata->navigationSort : static::$navigationSort;
    }

    #[Override]
    public static function getNavigationIcon(): string|BackedEnum|null
    {
        $metadata = static::settingsMetadata();

        return $metadata instanceof SettingsGroupMetadata ? $metadata->icon : static::$navigationIcon;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return static::settingsMetadata()?->getLabel() ?? str(static::settingsGroup())->headline()->toString();
    }

    /**
     * @return array<Action | ActionGroup>
     */
    #[Override]
    public function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            ...$this->extensionLifecycleFormActions(),
        ];
    }

    #[Override]
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->settingsFormSchema($schema))
            ->columns();
    }

    #[Override]
    public function save(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        try {
            $this->beginDatabaseTransaction();

            $this->callHook('beforeValidate');
            $data = $this->form->getState();
            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeSave($data);

            $this->callHook('beforeSave');

            PersistMissingSettingsDefaultsAction::run($this->settingsClass());

            $settings = resolve($this->settingsClass());
            $settings->fill($data);
            $settings->save();

            $this->callHook('afterSave');
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        $this->rememberData();

        $this->getSavedNotification()?->send();

        if ((! in_array($redirectUrl = $this->getRedirectUrl(), [null, '', '0'], true))) {
            $this->redirect($redirectUrl, navigate: FilamentView::hasSpaMode($redirectUrl));
        }
    }

    protected static function settingsGroup(): string
    {
        return static::$settingsGroup;
    }

    protected static function settingsMetadata(): ?SettingsGroupMetadata
    {
        return resolve(SettingsSchemaRegistry::class)->getMetadata(static::settingsGroup());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return resolve($this->settingsClass())->toArray();
    }

    /**
     * @return class-string
     */
    protected function settingsClass(): string
    {
        $settingsClass = resolve(SettingsSchemaRegistry::class)->getSettingsClass(static::settingsGroup());

        throw_if(
            $settingsClass === null,
            RuntimeException::class,
            sprintf('No settings class registered for settings group [%s].', static::settingsGroup()),
        );

        return $settingsClass;
    }

    /**
     * @return array<int, mixed>
     */
    private function settingsFormSchema(Schema $schema): array
    {
        $components = [];

        foreach (resolve(SettingsSchemaRegistry::class)->getSchemas(static::settingsGroup()) as $schemaClass) {
            $components = array_merge($components, BuildSettingsSchemaComponentsAction::run($schemaClass, $schema));
        }

        return $components;
    }

    /**
     * @return array<Action | ActionGroup>
     */
    private function extensionLifecycleFormActions(): array
    {
        if (! ExtensionsPage::canManageExtensions() || $this->lifecyclePackageName() === null) {
            return [];
        }

        return [
            $this->disableExtensionAction(),
            $this->uninstallExtensionAction(),
        ];
    }

    private function disableExtensionAction(): Action
    {
        return Action::make('disableExtension')
            ->label(__('capell-admin::button.disable_extension'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('capell-admin::generic.disable_extension_heading', [
                'extension' => $this->lifecyclePackageLabel(),
            ]))
            ->action(function (): void {
                $package = $this->lifecyclePackage();

                if (! $package instanceof PackageData) {
                    return;
                }

                DisablePackageAction::run($package);

                Notification::make('extension-disabled')
                    ->title(__('capell-admin::message.extension_disabled'))
                    ->body(__('capell-admin::message.extension_disabled_body'))
                    ->success()
                    ->send();

                $this->redirect(ExtensionsPage::getUrl());
            })
            ->visible(fn (): bool => $this->lifecyclePackageEnabled());
    }

    private function uninstallExtensionAction(): Action
    {
        return Action::make('uninstallExtension')
            ->label(__('capell-admin::button.uninstall_extension'))
            ->tooltip(fn (): string => $this->settingsUninstallTooltip())
            ->icon(Heroicon::OutlinedTrash)
            ->color(fn (): string => $this->settingsUninstallCanRun() ? 'danger' : 'gray')
            ->outlined(fn (): bool => ! $this->settingsUninstallCanRun())
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('capell-admin::generic.uninstall_extension_heading', [
                'extension' => $this->lifecyclePackageLabel(),
            ]))
            ->modalDescription(fn (): string => $this->uninstallAvailability()->modalDescription)
            ->modalSubmitAction(fn (Action $action): Action|bool => $this->settingsUninstallCanRun() ? $action : false)
            ->modalCancelActionLabel(fn (): string => $this->settingsUninstallCanRun()
                ? __('filament-actions::modal.actions.cancel.label')
                : __('capell-admin::button.close'))
            ->schema(fn (): array => $this->settingsUninstallCanRun() ? [
                $this->deleteExtensionPackageCheckbox(),
                $this->deleteExtensionDataCheckbox(),
            ] : [])
            ->action(function (array $data): void {
                $package = $this->lifecyclePackage();
                $availability = $this->uninstallAvailability();

                if (! $package instanceof PackageData || ! $this->settingsUninstallCanRun()) {
                    return;
                }

                $deletePackage = ($data['delete_extension_package'] ?? false) === true;

                try {
                    UninstallPackageAction::run(
                        $package,
                        delete: $deletePackage,
                        deleteData: ($data['delete_extension_data'] ?? false) === true,
                    );
                } catch (Throwable $throwable) {
                    Notification::make('extension-uninstall-failed')
                        ->title(__('capell-admin::message.extension_uninstall_failed', [
                            'extension' => $package->getShortName(),
                        ]))
                        ->body($throwable->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make('extension-uninstalled')
                    ->title(__($deletePackage ? 'capell-admin::message.extension_deleted' : 'capell-admin::message.extension_uninstalled', [
                        'extension' => $package->getShortName(),
                    ]))
                    ->body(__($deletePackage ? 'capell-admin::message.extension_deleted_body' : 'capell-admin::message.extension_uninstalled_body'))
                    ->success()
                    ->send();

                $this->redirect(ExtensionsPage::getUrl());
            })
            ->visible(fn (): bool => $this->uninstallAvailability()->visible);
    }

    private function settingsUninstallCanRun(): bool
    {
        $availability = $this->uninstallAvailability();

        return $availability->canRun && ! $availability->requiresDependentConfirmation;
    }

    private function settingsUninstallTooltip(): string
    {
        $availability = $this->uninstallAvailability();

        if ($availability->requiresDependentConfirmation && $availability->blockReason !== null) {
            return $availability->blockReason;
        }

        return $availability->tooltip;
    }

    private function deleteExtensionPackageCheckbox(): Checkbox
    {
        return Checkbox::make('delete_extension_package')
            ->label(__('capell-admin::generic.delete_extension_package'))
            ->helperText(__('capell-admin::generic.delete_extension_package_help'))
            ->default(false);
    }

    private function lifecyclePackageEnabled(): bool
    {
        $package = $this->lifecyclePackage();

        return $package instanceof PackageData
            && CapellCore::isPackageEnabled($package->name)
            && ! $package->isCore();
    }

    private function uninstallAvailability(): ExtensionUninstallAvailabilityData
    {
        $packageName = $this->lifecyclePackageName();

        return ResolveExtensionUninstallAvailabilityAction::run(
            packageName: $packageName ?? '',
            package: $this->lifecyclePackage(),
        );
    }

    private function deleteExtensionDataCheckbox(): Checkbox
    {
        return Checkbox::make('delete_extension_data')
            ->label(__('capell-admin::generic.delete_extension_data'))
            ->helperText(__('capell-admin::generic.delete_extension_data_help'))
            ->default(false);
    }

    private function lifecyclePackageLabel(): string
    {
        return $this->lifecyclePackage()?->getShortName()
            ?? static::settingsMetadata()?->getLabel()
            ?? static::settingsGroup();
    }

    private function lifecyclePackageName(): ?string
    {
        $packageName = static::settingsMetadata()?->packageName;

        return is_string($packageName) && $packageName !== '' ? $packageName : null;
    }

    private function lifecyclePackage(): ?PackageData
    {
        $packageName = $this->lifecyclePackageName();

        if ($packageName === null || ! CapellCore::hasPackage($packageName)) {
            return null;
        }

        return CapellCore::getPackage($packageName);
    }
}
