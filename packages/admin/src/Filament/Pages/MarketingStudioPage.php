<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\NormalizeDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\DashboardFilamentWidgetSettings;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Auth\Access\AuthorizationException;
use Override;

final class MarketingStudioPage extends Dashboard
{
    use HasPageShield;

    public const string MANAGE_PERMISSION = 'Manage:MarketingStudioPage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Megaphone;

    protected static ?string $slug = 'marketing-studio';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = -90;

    protected string $view = 'capell-admin::filament.pages.marketing-studio';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.marketing_studio');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    #[Override]
    public static function getRoutePath(Panel $panel): string
    {
        return '/' . self::getSlug($panel);
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-admin::marketing-studio.title');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-admin::marketing-studio.subheading');
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getWidgetsContentComponent(),
        ]);
    }

    #[Override]
    public function getColumns(): array
    {
        return ['default' => 1, '@3xl' => 12, '!@lg' => 12];
    }

    #[Override]
    public function getWidgetsContentComponent(): Grid
    {
        return Grid::make($this->getColumns())
            ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets()))
            ->gridContainer();
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    public function getWidgets(): array
    {
        return $this->configuredDashboardFilamentWidgets(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio));
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->customiseDashboardAction(),
        ];
    }

    /**
     * @param  list<class-string<Widget>>  $widgets
     * @return list<class-string<Widget>|WidgetConfiguration>
     */
    private function configuredDashboardFilamentWidgets(array $widgets): array
    {
        $settings = resolve(AdminSettings::class);

        return array_values(collect($widgets)
            ->filter(function (string $widgetClass) use ($settings): bool {
                if (! method_exists($widgetClass, 'settingsKey')) {
                    return true;
                }

                $settingsKey = $widgetClass::settingsKey();

                return ! is_string($settingsKey)
                    || $settingsKey === ''
                    || $settings->isWidgetEnabled($settingsKey);
            })
            ->values()
            ->all());
    }

    private function customiseDashboardAction(): Action
    {
        return Action::make('customiseMarketingStudioDashboard')
            ->label(__('capell-admin::button.customise_dashboard'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->authorize(fn (): bool => SettingsPage::canAccess())
            ->visible(fn (): bool => SettingsPage::canAccess())
            ->modalHeading(__('capell-admin::marketing-studio.customise_heading'))
            ->modalDescription(__('capell-admin::marketing-studio.customise_description'))
            ->modalSubmitActionLabel(__('capell-admin::button.save'))
            ->slideOver()
            ->schema([
                DashboardFilamentWidgetSettings::make()
                    ->hiddenLabel()
                    ->widgets(fn (): array => DashboardSettingsSchema::allContributedKeys(DashboardEnum::MarketingStudio)),
            ])
            ->fillForm(fn (): array => [
                'widget_layout' => $this->filamentDashboardWidgetLayoutState(),
            ])
            ->action(fn (array $data): null => $this->saveDashboardLayout($data));
    }

    /**
     * @return list<array{key: string, label: string, group: string, description: string|null, enabled: bool, order: int}>
     */
    private function filamentDashboardWidgetLayoutState(): array
    {
        return DashboardFilamentWidgetSettings::make()
            ->widgets(DashboardSettingsSchema::allContributedKeys(DashboardEnum::MarketingStudio))
            ->layoutState();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveDashboardLayout(array $data): null
    {
        throw_unless(SettingsPage::canAccess(), AuthorizationException::class);

        $normalised = NormalizeDashboardFilamentWidgetSettingsAction::run($data, DashboardEnum::MarketingStudio);
        $settings = AdminSettings::instance();

        foreach (['enabled_widgets', 'widget_order'] as $settingKey) {
            $value = $normalised[$settingKey] ?? null;

            if (is_array($value)) {
                $settings->{$settingKey} = $value;
            }
        }

        $settings->save();

        Notification::make('marketing_studio_dashboard_customised')
            ->status('success')
            ->title(__('capell-admin::notification.dashboard_customised'))
            ->send();

        return null;
    }
}
