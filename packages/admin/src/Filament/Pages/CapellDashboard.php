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
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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
                ->default(fn (): string => $this->defaultDashboardPeriod())
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
        $widgets = array_values(array_filter(
            $this->getWidgets(),
            fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget)::canView(),
        ));
        $dashboard = $this->dashboardEnum();
        $registeredClasses = [];
        $byRegion = [];

        foreach (DashboardRegionEnum::cases() as $region) {
            $regionClasses = CapellAdmin::getDashboardFilamentWidgetsByRegion($dashboard, $region);
            $regionWidgets = array_values(array_filter(
                $widgets,
                fn (string|WidgetConfiguration $widget): bool => in_array($this->normalizeWidgetClass($widget), $regionClasses, true),
            ));
            $registeredClasses = [...$registeredClasses, ...$regionClasses];
            $byRegion[$region->value] = $regionWidgets;
        }

        $byRegion[DashboardRegionEnum::Additional->value] = [
            ...$byRegion[DashboardRegionEnum::Additional->value],
            ...array_values(array_filter(
                $widgets,
                fn (string|WidgetConfiguration $widget): bool => ! in_array($this->normalizeWidgetClass($widget), $registeredClasses, true),
            )),
        ];

        $regions = [];
        $leadWidgets = [
            ...$byRegion[DashboardRegionEnum::Trends->value],
            ...$byRegion[DashboardRegionEnum::Insights->value],
        ];

        if ($leadWidgets !== []) {
            $regions[] = Grid::make(['default' => 1, 'lg' => min(2, count($leadWidgets))])
                ->schema(array_map(
                    fn (Component|Action|ActionGroup $component): Grid => Grid::make(1)
                        ->schema([$component])
                        ->columnSpan(1),
                    $this->getWidgetsSchemaComponents($leadWidgets),
                ))
                ->columnSpanFull();
        }

        foreach ([DashboardRegionEnum::Pulse, DashboardRegionEnum::Activity, DashboardRegionEnum::Additional] as $region) {
            $regionWidgets = $byRegion[$region->value];

            if ($regionWidgets === []) {
                continue;
            }

            $components = $this->getWidgetsSchemaComponents($regionWidgets);
            $regions[] = $region === DashboardRegionEnum::Additional
                ? Section::make($region->getLabel())
                    ->schema($components)
                    ->columns($this->getColumns())
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
                : Grid::make($this->getColumns())
                    ->schema($components)
                    ->columnSpanFull();
        }

        if ($regions === []) {
            $regions[] = Section::make(__('capell-admin::dashboard.empty_heading'))
                ->schema([Text::make(__('capell-admin::dashboard.empty_description'))])
                ->columnSpanFull();
        }

        return Grid::make($this->getColumns())
            ->schema($regions)
            ->columnSpanFull();
    }

    /**
     * @param  array<string | WidgetConfiguration>  $widgets
     * @param  array<string, mixed>  $data
     * @return array<Component | Action | ActionGroup>
     */
    #[Override]
    public function getWidgetsSchemaComponents(array $widgets, array $data = []): array
    {
        return collect($widgets)
            ->values()
            ->filter(function (string|WidgetConfiguration $widget): bool {
                /** @var class-string<Widget>|WidgetConfiguration $widget */
                return $this->normalizeWidgetClass($widget)::canView();
            })
            ->map(function (string|WidgetConfiguration $widget, int $widgetKey) use ($data): Livewire {
                /** @var class-string<Widget>|WidgetConfiguration $widget */
                $widgetClass = $this->normalizeWidgetClass($widget);

                return Livewire::make(
                    $widgetClass,
                    fn (): array => [
                        ...$this->getWidgetData(),
                        ...$data,
                        ...(($widget instanceof WidgetConfiguration) ? [
                            ...$widget->widget::getDefaultProperties(),
                            ...$widget->getProperties(),
                        ] : $widget::getDefaultProperties()),
                        ...(property_exists($widgetClass, 'pageFilters') ? ['pageFilters' => $this->filters] : []),
                    ],
                )->key(sprintf('%s-%d', $widgetClass, $widgetKey))->liberatedFromContainerGrid();
            })
            ->all();
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

        return $this->configuredDashboardFilamentWidgets(array_values(array_unique([
            ...CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main),
            ...CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio),
        ], SORT_REGULAR)));
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->reportsAction(),
            $this->upgradeAction(),
        ]));
    }

    private function reportsAction(): ?ActionGroup
    {
        $actions = [];

        if (SiteHealthPage::canAccess()) {
            $actions[] = Action::make('siteHealth')
                ->label(SiteHealthPage::getNavigationLabel())
                ->icon(Heroicon::OutlinedHeart)
                ->url(SiteHealthPage::getUrl());
        }

        foreach (CapellAdmin::getReports() as $report) {
            $pageClass = $report->pageClass;
            if (! is_subclass_of($pageClass, Page::class)) {
                continue;
            }

            if (! $pageClass::canAccess()) {
                continue;
            }

            if (! $pageClass::shouldRegisterNavigation()) {
                continue;
            }

            $actions[] = Action::make('report_' . $report->key)
                ->label($report->resolvedLabel())
                ->url($pageClass::getUrl());
        }

        return $actions === [] ? null : ActionGroup::make($actions)
            ->label(__('capell-admin::navigation.group_reports'))
            ->icon(Heroicon::OutlinedChartBar)
            ->button()
            ->color('gray');
    }

    private function defaultDashboardPeriod(): string
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
