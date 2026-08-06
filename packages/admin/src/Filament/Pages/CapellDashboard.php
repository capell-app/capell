<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Data\Dashboard\DashboardFilterStateData;
use Capell\Admin\Enums\DashboardDateRangeEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Override;

class CapellDashboard extends Dashboard
{
    use HasFiltersForm {
        updatedFilters as filtersFormUpdatedFilters;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = -100;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            SiteSelect::make('site_id')
                ->label(__('capell-admin::form.site'))
                ->default(null)
                ->placeholder(__('capell-admin::dashboard.filter_all_sites'))
                ->selectablePlaceholder(),
            Select::make('language')
                ->label(__('capell-admin::form.language'))
                ->options(fn (): array => Language::query()
                    ->enabled()
                    ->orderByDesc('default')
                    ->orderBy('name')
                    ->pluck('name', 'code')
                    ->all())
                ->default(null)
                ->placeholder(__('capell-admin::dashboard.filter_all_languages'))
                ->selectablePlaceholder(),
            ToggleButtons::make('date_range')
                ->options(DashboardDateRangeEnum::options())
                ->columnSpanFull()
                ->default(fn (): string => self::defaultDashboardPeriod())
                ->extraAttributes([
                    'style' => 'grid-auto-columns: max-content; max-width: 100%; white-space: nowrap; width: max-content;',
                ])
                ->extraFieldWrapperAttributes(['class' => 'w-full'])
                ->inline()
                ->grouped(),
        ]);
    }

    public function updatedFilters(): void
    {
        $this->filtersFormUpdatedFilters();
        $filters = is_array($this->filters) ? $this->filters : [];
        $state = DashboardFilterStateData::fromFilters($filters);

        $this->dispatch(
            'dashboardFilterChanged',
            period: $state->period->value,
            siteId: $state->siteId,
            language: $state->language,
            refresh: $state->refresh,
        );
    }

    /**
     * @return array<string, int>
     */
    #[Override]
    public function getColumns(): array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    #[Override]
    public function getFiltersFormContentComponent(): Component
    {
        return parent::getFiltersFormContentComponent()
            ->columnSpanFull();
    }

    #[Override]
    public function getWidgetsContentComponent(): Grid
    {
        $widgets = $this->getWidgets();
        $widgetClasses = array_values(array_filter(
            $widgets,
            static fn (string|WidgetConfiguration $widget): bool => is_string($widget),
        ));
        $dashboard = $this->dashboardEnum();
        $regions = [];
        $registeredClasses = [];

        foreach (DashboardRegionEnum::cases() as $region) {
            $regionWidgets = array_values(array_intersect(
                CapellAdmin::getDashboardFilamentWidgetsByRegion($dashboard, $region),
                $widgetClasses,
            ));

            if ($regionWidgets === [] && $region === DashboardRegionEnum::Additional) {
                continue;
            }

            $registeredClasses = [...$registeredClasses, ...$regionWidgets];
            $regions[] = Section::make($region->getLabel())
                ->columnSpanFull()
                ->schema($this->getWidgetsSchemaComponents($regionWidgets));
        }

        $unregisteredWidgets = array_values(array_diff($widgetClasses, $registeredClasses));

        if ($unregisteredWidgets !== []) {
            $regions[] = Section::make(DashboardRegionEnum::Additional->getLabel())
                ->columnSpanFull()
                ->schema($this->getWidgetsSchemaComponents($unregisteredWidgets));
        }

        return Grid::make($this->getColumns())
            ->schema($regions)
            ->columnSpanFull();
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    #[Override]
    public function getWidgets(): array
    {
        if (! CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
            || ! resolve(RuntimeSchemaState::class)->hasTable((new Site)->getTable())
            || ! Site::query()->exists()) {
            return CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled);
        }

        return $this->configuredDashboardFilamentWidgets(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main));
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->upgradeAction(),
        ]));
    }

    private static function defaultDashboardPeriod(): string
    {
        return match (max(1, min(365, AdminSettings::instance()->analytics_default_period_days))) {
            1 => DashboardDateRangeEnum::Today->value,
            7 => DashboardDateRangeEnum::ThisWeek->value,
            30 => DashboardDateRangeEnum::Last30Days->value,
            365 => DashboardDateRangeEnum::ThisYear->value,
            default => DashboardDateRangeEnum::Last30Days->value,
        };
    }

    private function dashboardEnum(): DashboardEnum
    {
        return CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
            && resolve(RuntimeSchemaState::class)->hasTable((new Site)->getTable())
            && Site::query()->exists()
            ? DashboardEnum::Main
            : DashboardEnum::NotInstalled;
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

    private function upgradeAction(): ?Action
    {
        if (! UpgradePage::canAccess()) {
            return null;
        }

        $badge = UpgradePage::getNavigationBadge();

        if ($badge === null || $badge === '') {
            return null;
        }

        return Action::make('openUpgrade')
            ->label(__('capell-admin::button.review_upgrades'))
            ->icon(Heroicon::OutlinedCloudArrowUp)
            ->color(UpgradePage::getNavigationBadgeColor() ?? 'warning')
            ->badge($badge)
            ->url(UpgradePage::getUrl());
    }
}
