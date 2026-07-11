<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Actions\NormalizeDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Filament\Components\Forms\DashboardFilamentWidgetSettings;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

trait CustomisesExtensionsDashboard
{
    protected function customiseExtensionsDashboardAction(): Action
    {
        return Action::make('customiseExtensionsDashboard')
            ->label(__('capell-admin::button.customise_dashboard'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->authorize(fn (): bool => SettingsPage::canAccess())
            ->visible(fn (): bool => SettingsPage::canAccess())
            ->modalHeading(__('capell-admin::heading.customise_extensions_dashboard'))
            ->modalDescription(__('capell-admin::generic.customise_extensions_dashboard_description'))
            ->modalSubmitActionLabel(__('capell-admin::button.save'))
            ->slideOver()
            ->schema([
                DashboardFilamentWidgetSettings::make()
                    ->hiddenLabel()
                    ->widgets(fn (): array => DashboardSettingsSchema::allContributedKeys(DashboardEnum::Extensions)),
            ])
            ->fillForm(fn (): array => [
                'widget_layout' => $this->filamentDashboardWidgetLayoutState(),
            ])
            ->action(fn (array $data): null => $this->saveExtensionsDashboardLayout($data));
    }

    /**
     * @return list<array{key: string, label: string, group: string, description: string|null, enabled: bool, order: int}>
     */
    private function filamentDashboardWidgetLayoutState(): array
    {
        return DashboardFilamentWidgetSettings::make()
            ->widgets(DashboardSettingsSchema::allContributedKeys(DashboardEnum::Extensions))
            ->layoutState();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveExtensionsDashboardLayout(array $data): null
    {
        throw_unless(SettingsPage::canAccess(), AuthorizationException::class);

        $normalised = NormalizeDashboardFilamentWidgetSettingsAction::run($data, DashboardEnum::Extensions);
        $settings = AdminSettings::instance();

        foreach (['enabled_widgets', 'widget_order'] as $settingKey) {
            $value = $normalised[$settingKey] ?? null;

            if (is_array($value)) {
                $settings->{$settingKey} = $value;
            }
        }

        $settings->save();

        Notification::make('extensions_dashboard_customised')
            ->status('success')
            ->title(__('capell-admin::notification.dashboard_customised'))
            ->send();

        return null;
    }
}
