<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use Capell\Admin\Data\Dashboard\DashboardFilterStateData;
use Capell\Admin\Data\Dashboard\DashboardScopePreferenceData;
use Capell\Admin\Enums\DashboardDateRangeEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\SiteSelect;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellAccountFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellInfoFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\NeedsAttentionFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ApprovalsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\AttentionWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ContentStatusWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PageViewsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PopularPagesWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PublishingHealthWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\RecentlyUpdatedWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\RisingPagesWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\TeamActivityWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\UrgentSummaryWidget;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;

class CapellDashboard extends Dashboard
{
    use HasFiltersForm {
        updatedFilters as filtersFormUpdatedFilters;
        mountHasFilters as filtersFormMountHasFilters;
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = -100;

    /** @var list<string>|null */
    private ?array $layoutWidgetKeysCache = null;

    public function filtersForm(Schema $schema): Schema
    {
        if ($this->workFirstEnabled()) {
            $schema->columns(['default' => 1, 'md' => 3, 'xl' => 4]);
        }

        return $schema->components([
            ($this->workFirstEnabled()
                ? Select::make('site_id')->options(fn (): array => SiteAccess::forActor(auth()->user(), acrossAssignedSites: true)->query(Site::class)->orderBy('name')->pluck('name', 'id')->all())
                : SiteSelect::make('site_id'))
                ->label(__('capell-admin::form.site'))
                ->default(null)
                ->placeholder(__('capell-admin::dashboard.filter_all_sites'))
                ->selectablePlaceholder(),
            Select::make('language')
                ->label(__('capell-admin::form.language'))
                ->options(fn (): array => $this->workFirstEnabled() ? $this->workFirstLanguages() : Language::query()
                    ->enabled()
                    ->orderByDesc('default')
                    ->orderBy('name')
                    ->pluck('name', 'code')
                    ->all())
                ->visible(fn (): bool => ! $this->workFirstEnabled() || count($this->workFirstLanguages()) > 1)
                ->default(null)
                ->placeholder(__('capell-admin::dashboard.filter_all_languages'))
                ->selectablePlaceholder(),
            Select::make('date_range')
                ->label($this->periodFilterLabel())
                ->helperText(fn (): ?string => $this->workFirstEnabled() ? (string) __('capell-admin::dashboard.work_first_period_help') : null)
                ->options(fn (): array => $this->workFirstEnabled() ? [
                    DashboardDateRangeEnum::Today->value => __('capell-admin::dashboard.work_first_period_1'),
                    DashboardDateRangeEnum::ThisWeek->value => __('capell-admin::dashboard.work_first_period_7'),
                    DashboardDateRangeEnum::Last30Days->value => __('capell-admin::dashboard.work_first_period_30'),
                    DashboardDateRangeEnum::ThisYear->value => __('capell-admin::dashboard.work_first_period_365'),
                ] : DashboardDateRangeEnum::options())
                ->selectablePlaceholder(false)
                ->default(fn (): string => $this->defaultDashboardPeriod()),
        ]);
    }

    public function mountHasFilters(): void
    {
        $actor = auth()->user();
        if ($this->workFirstEnabled() && $this->filters === null && $actor instanceof Model) {
            $scope = resolve(AdminWorkspacePreferenceStore::class)->dashboardScope($actor);
            if ($scope !== null) {
                $siteId = $scope->siteId;
                if ($siteId !== null && ! SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->whereKey($siteId)->exists()) {
                    $siteId = -1;
                }

                $this->filters = ['site_id' => $siteId, 'language' => $scope->language, 'date_range' => DashboardDateRangeEnum::Last30Days->value];
            }
        }

        $this->filtersFormMountHasFilters();
    }

    public function updatedFilters(): void
    {
        $this->layoutWidgetKeysCache = null;
        $actor = auth()->user();
        if ($this->workFirstEnabled() && $actor instanceof Model && is_array($this->filters)) {
            $language = $this->filters['language'] ?? null;
            if (is_string($language) && ! array_key_exists($language, $this->workFirstLanguages())) {
                $this->filters['language'] = null;
            }

            $state = DashboardFilterStateData::fromFilters($this->filters);
            $siteId = $this->workFirstSiteFilter();
            if ($siteId === null || ($siteId > 0 && SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->whereKey($siteId)->exists())) {
                resolve(AdminWorkspacePreferenceStore::class)->saveDashboardScope($actor, new DashboardScopePreferenceData($siteId, $state->language));
            }
        }

        $this->filtersFormUpdatedFilters();
        $filters = is_array($this->filters) ? $this->filters : [];
        $state = DashboardFilterStateData::fromFilters($filters);

        $this->dispatch(
            'dashboardFilterChanged',
            period: $state->period->value,
            siteId: $this->workFirstEnabled() ? $this->workFirstSiteFilter() : $state->siteId,
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
    public function content(Schema $schema): Schema
    {
        $widgets = $this->getWidgets();
        if ($this->dashboardEnum() === DashboardEnum::NotInstalled) {
            return $schema->components([
                Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($widgets))->columnSpanFull(),
            ]);
        }

        if ($this->workFirstEnabled()) {
            return $this->workFirstContent($schema, $widgets);
        }

        $welcome = array_values(array_filter($widgets, $this->isWelcomeWidget(...)));
        $components = [];

        $welcomeComponents = $this->getWidgetsSchemaComponents($welcome);
        if ($welcomeComponents !== []) {
            $components[] = Grid::make(['default' => 1, 'lg' => min(2, count($welcomeComponents))])
                ->schema($welcomeComponents)
                ->columnSpanFull();
        }

        $components[] = $this->getFiltersFormContentComponent();
        $attention = array_values(array_filter($widgets, fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget) === NeedsAttentionFilamentWidget::class));
        if ($this->getWidgetsSchemaComponents($attention) !== []) {
            $components[] = Grid::make(1)->schema($this->getWidgetsSchemaComponents($attention))->columnSpanFull();
        }

        $components[] = $this->getWidgetsContentComponent();

        return $schema->components($components);
    }

    #[Override]
    public function getWidgetsContentComponent(): Grid
    {
        $widgets = array_values(array_filter($this->getWidgets(), fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget)::canView()
            && ! $this->isWelcomeWidget($widget)
            && $this->normalizeWidgetClass($widget) !== NeedsAttentionFilamentWidget::class));
        $regions = [];

        foreach ([DashboardRegionEnum::Pulse, DashboardRegionEnum::Trends, DashboardRegionEnum::Insights] as $region) {
            $classes = CapellAdmin::getDashboardFilamentWidgetsByRegion($this->dashboardEnum(), $region);
            $byRegion[$region->value] = array_values(array_filter($widgets, fn (string|WidgetConfiguration $widget): bool => in_array($this->normalizeWidgetClass($widget), $classes, true)));
        }

        $performance = [];
        if ($byRegion[DashboardRegionEnum::Pulse->value] !== []) {
            $performance[] = Grid::make($this->getColumns())
                ->schema($this->getWidgetsSchemaComponents($byRegion[DashboardRegionEnum::Pulse->value]))
                ->columnSpanFull();
        }

        $lead = [...$byRegion[DashboardRegionEnum::Trends->value], ...$byRegion[DashboardRegionEnum::Insights->value]];
        if ($lead !== []) {
            $performance[] = Grid::make(['default' => 1, 'lg' => min(2, count($lead))])
                ->schema(array_map(fn (Component|Action|ActionGroup $component): Grid => Grid::make(1)
                    ->schema([$component])->columnSpan(1), $this->getWidgetsSchemaComponents($lead)))
                ->columnSpanFull();
        }

        if ($performance !== []) {
            $regions[] = Section::make(__('capell-admin::dashboard.performance_heading'))
                ->description(__('capell-admin::dashboard.performance_description'))
                ->headerActions(AnalyticsDashboard::canAccess() ? [Action::make('exploreAnalytics')
                    ->label(__('capell-admin::dashboard.explore_analytics'))
                    ->url(fn (): string => AnalyticsDashboard::getUrl(['filters' => $this->filters ?? []]))] : [])
                ->schema($performance)->columnSpanFull();
        }

        $activityClasses = CapellAdmin::getDashboardFilamentWidgetsByRegion($this->dashboardEnum(), DashboardRegionEnum::Activity);
        $activity = array_values(array_filter($widgets, fn (string|WidgetConfiguration $widget): bool => in_array($this->normalizeWidgetClass($widget), $activityClasses, true)
            && $this->normalizeWidgetClass($widget) !== AnalyticsRecentActivityFilamentWidget::class));
        if ($activity !== []) {
            $regions[] = Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($activity))->columnSpanFull();
        }

        $additionalClasses = CapellAdmin::getDashboardFilamentWidgetsByRegion($this->dashboardEnum(), DashboardRegionEnum::Additional);
        $additional = array_values(array_filter($widgets, fn (string|WidgetConfiguration $widget): bool => in_array($this->normalizeWidgetClass($widget), $additionalClasses, true)));
        if ($additional !== []) {
            $regions[] = Section::make(__('capell-admin::dashboard.region_additional'))
                ->description(__('capell-admin::dashboard.additional_description'))
                ->schema([
                    Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($additional))->columnSpanFull(),
                ])
                ->columnSpanFull();
        }

        if ($regions === []) {
            $regions[] = Section::make(__('capell-admin::dashboard.empty_heading'))
                ->schema([Text::make(__('capell-admin::dashboard.overview_empty_description'))])
                ->columnSpanFull();
        }

        return Grid::make(1)->schema($regions)->columnSpanFull();
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
                $widgetClass = $this->normalizeWidgetClass($widget);

                return $this->workFirstClassEnabled($widgetClass) && $widgetClass::canView();
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
                        ...($this->workFirstEnabled() && property_exists($widgetClass, 'dashboardSize') ? ['dashboardSize' => $this->dashboardWidgetOptions()['sizes'][$widgetClass] ?? 'standard'] : []),
                        ...($this->workFirstEnabled() && property_exists($widgetClass, 'attentionVisible') ? ['attentionVisible' => $this->isWorkFirstAttentionVisible()] : []),
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
            ...($this->workFirstEnabled() ? [...$this->defaultWorkFirstWidgets(), ContentStatusWidget::class, RecentlyUpdatedWidget::class] : []),
            ...CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main),
            ...($this->workFirstEnabled() ? [] : CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio)),
        ], SORT_REGULAR)));
    }

    /** @param class-string<Widget>|WidgetConfiguration $widget */
    protected function isWelcomeWidget(string|WidgetConfiguration $widget): bool
    {
        $class = $this->normalizeWidgetClass($widget);

        return is_a($class, AccountWidget::class, true) || is_a($class, FilamentInfoWidget::class, true);
    }

    protected function periodFilterLabel(): string
    {
        return (string) ($this->workFirstEnabled() ? __('capell-admin::dashboard.work_first_analytics_period') : __('capell-admin::dashboard.performance_period'));
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->workFirstEnabled() && $this->dashboardEnum() === DashboardEnum::Main ? $this->editWorkFirstLayoutAction() : null,
            $this->reportsAction(),
            $this->upgradeAction(),
        ]));
    }

    /**
     * @param  array<class-string<Widget>|WidgetConfiguration>  $widgets
     */
    private function workFirstContent(Schema $schema, array $widgets): Schema
    {
        $priority = $this->defaultWorkFirstWidgets();
        $leading = [];
        foreach ($priority as $class) {
            foreach ($widgets as $widget) {
                if ($this->normalizeWidgetClass($widget) === $class) {
                    $leading[] = $widget;
                }
            }
        }

        $remaining = array_values(array_filter($widgets, fn (string|WidgetConfiguration $widget): bool => ! in_array($this->normalizeWidgetClass($widget), $priority, true)));
        $hidden = $this->dashboardWidgetOptions()['hidden'];
        $ordered = array_values(array_filter([...$leading, ...$remaining], fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget) === UrgentSummaryWidget::class || ! in_array($this->normalizeWidgetClass($widget), $hidden, true)));
        $actor = auth()->user();
        $saved = $actor instanceof Model ? resolve(AdminWorkspacePreferenceStore::class)->dashboardLayout($actor, $this->allSitesSelected()) : null;
        if ($saved === null) {
            $ordered = array_values(array_filter($ordered, fn (string|WidgetConfiguration $widget): bool => in_array($this->normalizeWidgetClass($widget), $this->defaultWorkFirstWidgets(), true)));
        } else {
            $ordered = array_values(array_filter($ordered, fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget) === UrgentSummaryWidget::class || in_array($this->normalizeWidgetClass($widget), $saved, true)));
            $positions = array_flip($saved);
            $indexed = [];
            foreach ($ordered as $index => $widget) {
                $class = $this->normalizeWidgetClass($widget);
                $indexed[] = ['widget' => $widget, 'position' => $class === UrgentSummaryWidget::class ? -1 : ($positions[$class] ?? count($saved) + $index)];
            }

            usort($indexed, static fn (array $left, array $right): int => $left['position'] <=> $right['position']);
            $ordered = array_column($indexed, 'widget');
        }

        return $schema->components([
            $this->getFiltersFormContentComponent(),
            Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($ordered))->columnSpanFull(),
        ]);
    }

    private function editWorkFirstLayoutAction(): Action
    {
        return Action::make('editWorkFirstLayout')
            ->label(__('capell-admin::dashboard.work_first_edit_layout'))
            ->modalHeading(__('capell-admin::dashboard.work_first_edit_layout'))
            ->modalSubmitActionLabel(__('capell-admin::button.save'))
            ->schema([
                Toggle::make('restore_defaults')
                    ->label(__('capell-admin::dashboard.work_first_reset_layout'))
                    ->helperText(__('capell-admin::dashboard.work_first_reset_help'))
                    ->live(),
                ...array_map(fn (string $group): Section => Section::make($this->dashboardWidgetGroupLabel($group))
                    ->visible(fn (Get $get): bool => ! $get('restore_defaults') && $this->groupWidgetKeys($group) !== [])
                    ->schema([
                        CheckboxList::make('selected_' . $group)
                            ->hiddenLabel()
                            ->allowHtml()
                            ->options(fn (): array => $this->groupWidgetOptions($group)),
                    ]), ['work', 'content', 'performance', 'system', 'extensions']),
                Repeater::make('widgets')
                    ->label(__('capell-admin::dashboard.work_first_widget_order'))
                    ->helperText(__('capell-admin::dashboard.work_first_order_help'))
                    ->visible(fn (Get $get): bool => ! $get('restore_defaults'))
                    ->schema([
                        Hidden::make('key'),
                        Placeholder::make('name')->hiddenLabel()->content(fn (Get $get): string => $this->dashboardWidgetLabel((string) $get('key'))),
                        Select::make('size')->label(__('capell-admin::dashboard.work_first_widget_size'))
                            ->options(['standard' => __('capell-admin::dashboard.work_first_size_standard'), 'wide' => __('capell-admin::dashboard.work_first_size_wide')])
                            ->visible(fn (Get $get): bool => $this->supportsDashboardSize($get('key'))),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderableWithButtons()
                    ->reorderableWithDragAndDrop(),
            ])
            ->fillForm(fn (): array => $this->layoutFormData())
            ->action(function (array $data): void {
                $actor = auth()->user();
                if (! $this->workFirstEnabled() || ! $actor instanceof Model) {
                    return;
                }

                if (($data['restore_defaults'] ?? false) === true) {
                    resolve(AdminWorkspacePreferenceStore::class)->resetDashboardLayout($actor, $this->allSitesSelected());

                    return;
                }

                if (! is_array($data['widgets'] ?? null)) {
                    return;
                }

                $allowed = $this->layoutWidgetKeys();
                $keys = array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['key'] ?? null) : null, $data['widgets']);
                $submitted = array_values(array_filter($keys, is_string(...)));
                if (count($submitted) !== count($keys)
                    || count($submitted) !== count($allowed) || count(array_unique($submitted, SORT_REGULAR)) !== count($allowed)
                    || array_diff($submitted, $allowed) !== []) {
                    return;
                }

                $selected = [];
                foreach (['work', 'content', 'performance', 'system', 'extensions'] as $group) {
                    $groupSelection = $data['selected_' . $group] ?? [];
                    if (! is_array($groupSelection)) {
                        return;
                    }

                    $groupAllowed = $this->groupWidgetKeys($group);
                    foreach ($groupSelection as $key) {
                        if (! is_string($key) || ! in_array($key, $groupAllowed, true)) {
                            return;
                        }

                        $selected[] = $key;
                    }
                }

                $store = resolve(AdminWorkspacePreferenceStore::class);
                $old = $store->dashboardLayout($actor, $this->allSitesSelected()) ?? [];
                $options = $store->dashboardOptions($actor, $this->allSitesSelected());
                $hidden = [...array_values(array_diff($options['hidden'], $allowed)), ...array_values(array_diff($allowed, $selected))];
                $sizes = array_diff_key($options['sizes'], array_flip($allowed));
                foreach ($data['widgets'] as $entry) {
                    $key = $entry['key'];
                    if (property_exists($key, 'dashboardSize') && in_array($entry['size'] ?? null, ['standard', 'wide'], true)) {
                        $sizes[$key] = $entry['size'];
                    }
                }

                $store->saveDashboardPreferences($actor, $this->allSitesSelected(), [...$submitted, ...array_values(array_diff($old, $allowed))], $hidden, $sizes);
            });
    }

    /** @return list<string> */
    private function layoutWidgetKeys(): array
    {
        if ($this->layoutWidgetKeysCache !== null) {
            return $this->layoutWidgetKeysCache;
        }

        $keys = array_values(array_map(
            $this->normalizeWidgetClass(...),
            array_filter($this->getWidgets(), fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget) !== UrgentSummaryWidget::class && $this->normalizeWidgetClass($widget)::canView()),
        ));
        $actor = auth()->user();
        $saved = $actor instanceof Model ? resolve(AdminWorkspacePreferenceStore::class)->dashboardLayout($actor, $this->allSitesSelected()) : null;

        return $this->layoutWidgetKeysCache = $saved === null ? $keys : [...array_values(array_intersect($saved, $keys)), ...array_values(array_diff($keys, $saved))];
    }

    /** @return array{hidden: list<string>, sizes: array<string, string>} */
    private function dashboardWidgetOptions(): array
    {
        $actor = auth()->user();

        return $actor instanceof Model
            ? resolve(AdminWorkspacePreferenceStore::class)->dashboardOptions($actor, $this->allSitesSelected())
            : ['hidden' => [], 'sizes' => []];
    }

    /** @return list<array{key: string, visible: bool, size: string}> */
    private function layoutFormState(): array
    {
        $options = $this->dashboardWidgetOptions();
        $actor = auth()->user();
        $saved = $actor instanceof Model ? resolve(AdminWorkspacePreferenceStore::class)->dashboardLayout($actor, $this->allSitesSelected()) : null;

        return array_map(fn (string $key): array => [
            'key' => $key,
            'visible' => ($saved === null ? in_array($key, $this->defaultWorkFirstWidgets(), true) : in_array($key, $saved, true)) && ! in_array($key, $options['hidden'], true),
            'size' => $options['sizes'][$key] ?? 'standard',
        ], $this->layoutWidgetKeys());
    }

    /** @return array<string, mixed> */
    private function layoutFormData(): array
    {
        $items = $this->layoutFormState();
        $data = ['restore_defaults' => false, 'widgets' => $items];
        foreach (['work', 'content', 'performance', 'system', 'extensions'] as $group) {
            $data['selected_' . $group] = array_values(array_map(
                static fn (array $item): string => $item['key'],
                array_filter($items, fn (array $item): bool => $item['visible'] && $this->dashboardWidgetGroup($item['key']) === $group),
            ));
        }

        return $data;
    }

    /** @return list<string> */
    private function groupWidgetKeys(string $group): array
    {
        return array_values(array_filter($this->layoutWidgetKeys(), fn (string $key): bool => $this->dashboardWidgetGroup($key) === $group));
    }

    /** @return array<string, string> */
    private function groupWidgetOptions(string $group): array
    {
        $options = [];
        $visible = array_column(array_filter($this->layoutFormState(), static fn (array $item): bool => $item['visible']), 'key');
        foreach ($this->groupWidgetKeys($group) as $key) {
            $options[$key] = $this->dashboardWidgetPreview($key, in_array($key, $visible, true))->toHtml();
        }

        return $options;
    }

    private function dashboardWidgetGroup(string $key): string
    {
        $group = $this->dashboardWidgetMetadata($key)['group'] ?? null;
        if (is_string($group) && in_array($group, ['work', 'content', 'performance', 'system', 'extensions'], true)) {
            return $group;
        }

        return match ($key) {
            PersonalDraftsWidget::class, ApprovalsWidget::class, PublishingHealthWidget::class, TeamActivityWidget::class => 'work',
            PageViewsWidget::class, PopularPagesWidget::class, RisingPagesWidget::class => 'performance',
            AttentionWidget::class => 'system',
            ContentStatusWidget::class, RecentlyUpdatedWidget::class => 'content',
            default => 'extensions',
        };
    }

    /** @return array<string, mixed> */
    private function dashboardWidgetMetadata(string $key): array
    {
        $metadata = config('capell-admin.dashboard.work_first.widget_metadata', []);
        $entry = is_array($metadata) ? ($metadata[$key] ?? null) : null;

        return is_array($entry) ? $entry : [];
    }

    private function workFirstClassEnabled(string $widgetClass): bool
    {
        $switches = config('capell-admin.dashboard.work_first.widgets', []);
        if (! $this->workFirstEnabled()) {
            return true;
        }

        if (! is_array($switches)) {
            return true;
        }

        return ($switches[$widgetClass] ?? true) !== false;
    }

    private function isWorkFirstAttentionVisible(): bool
    {
        if (! AttentionWidget::canView() || in_array(AttentionWidget::class, $this->dashboardWidgetOptions()['hidden'], true)) {
            return false;
        }

        $actor = auth()->user();
        $saved = $actor instanceof Model ? resolve(AdminWorkspacePreferenceStore::class)->dashboardLayout($actor, $this->allSitesSelected()) : null;

        return $saved === null || in_array(AttentionWidget::class, $saved, true);
    }

    private function supportsDashboardSize(mixed $key): bool
    {
        return is_string($key) && in_array($key, $this->layoutWidgetKeys(), true) && property_exists($key, 'dashboardSize');
    }

    private function dashboardWidgetLabel(string $key): string
    {
        $label = $this->dashboardWidgetMetadata($key)['label'] ?? null;
        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        return match ($key) {
            PersonalDraftsWidget::class => (string) __('capell-admin::dashboard.work_first_drafts_heading'),
            ApprovalsWidget::class => (string) __('capell-admin::dashboard.work_first_approvals_heading'),
            AttentionWidget::class => (string) __('capell-admin::work-first.attention'),
            ContentStatusWidget::class => (string) __('capell-admin::dashboard.work_first_content_status'),
            RecentlyUpdatedWidget::class => (string) __('capell-admin::dashboard.work_first_recently_updated'),
            PublishingHealthWidget::class => (string) __('capell-admin::work-first.publishing'),
            TeamActivityWidget::class => (string) __('capell-admin::work-first.activity'),
            PageViewsWidget::class => (string) __('capell-admin::dashboard.work_first_page_views'),
            PopularPagesWidget::class => (string) __('capell-admin::dashboard.work_first_popular_pages'),
            RisingPagesWidget::class => (string) __('capell-admin::dashboard.work_first_rising_pages'),
            default => str(class_basename($key))->replace('FilamentWidget', '')->replace('Widget', '')->headline()->toString(),
        };
    }

    private function dashboardWidgetGroupLabel(string $group): string
    {
        return (string) match ($group) {
            'work' => __('capell-admin::dashboard.work_first_group_work'),
            'content' => __('capell-admin::dashboard.work_first_group_content'),
            'performance' => __('capell-admin::dashboard.work_first_group_performance'),
            'system' => __('capell-admin::dashboard.work_first_group_system'),
            default => __('capell-admin::dashboard.work_first_group_extensions'),
        };
    }

    private function dashboardWidgetPreview(string $key, bool $added): HtmlString
    {
        $group = $this->dashboardWidgetGroupLabel($this->dashboardWidgetGroup($key));
        $label = e($this->dashboardWidgetLabel($key));
        $groupLabel = e($group);
        $previewLabel = e((string) __('capell-admin::dashboard.work_first_preview'));
        $metadata = $this->dashboardWidgetMetadata($key);
        $description = $metadata['description'] ?? null;
        $purpose = e(is_string($description) && trim($description) !== '' ? $description : (string) match ($key) {
            PersonalDraftsWidget::class => __('capell-admin::dashboard.work_first_purpose_work'),
            ApprovalsWidget::class => __('capell-admin::dashboard.work_first_purpose_approvals'),
            AttentionWidget::class => __('capell-admin::dashboard.work_first_purpose_attention'),
            PublishingHealthWidget::class => __('capell-admin::dashboard.work_first_purpose_publishing'),
            TeamActivityWidget::class => __('capell-admin::dashboard.work_first_purpose_activity'),
            PageViewsWidget::class => __('capell-admin::dashboard.work_first_purpose_audience'),
            PopularPagesWidget::class, RisingPagesWidget::class => __('capell-admin::dashboard.work_first_purpose_rankings'),
            ContentStatusWidget::class, RecentlyUpdatedWidget::class => __('capell-admin::dashboard.work_first_purpose_content'),
            default => __('capell-admin::dashboard.work_first_purpose_extension'),
        });
        $requirements = $metadata['requirements'] ?? match ($key) {
            PageViewsWidget::class => __('capell-admin::dashboard.work_first_requirements_audience'),
            PopularPagesWidget::class, RisingPagesWidget::class => __('capell-admin::dashboard.work_first_requirements_rankings'),
            default => null,
        };
        $requirementsText = is_string($requirements) && trim($requirements) !== '' ? '<span class="block text-xs text-gray-500">' . e($requirements) . '</span>' : '';
        $addedText = $added ? '<span class="block text-xs font-medium">' . e((string) __('capell-admin::dashboard.work_first_show_widget')) . '</span>' : '';

        $shape = match ($key) {
            PageViewsWidget::class, PopularPagesWidget::class, RisingPagesWidget::class => '<svg width="48" height="28" viewBox="0 0 48 28" fill="currentColor" opacity="0.5"><rect x="3" y="20" width="8" height="6"/><rect x="17" y="12" width="8" height="14"/><rect x="31" y="2" width="8" height="24"/></svg>',
            AttentionWidget::class => '<svg width="48" height="28" viewBox="0 0 48 28" fill="currentColor" opacity="0.5"><circle cx="5" cy="14" r="4"/><rect x="14" y="12" width="30" height="4" rx="2"/></svg>',
            default => '<svg width="48" height="28" viewBox="0 0 48 28" fill="currentColor" opacity="0.5"><rect x="2" y="4" width="32" height="4" rx="2"/><rect x="2" y="12" width="42" height="4" rx="2" opacity="0.5"/><rect x="2" y="20" width="24" height="4" rx="2" opacity="0.5"/></svg>',
        };

        return new HtmlString('<div class="flex items-center gap-3"><span aria-hidden="true" class="flex h-10 w-14 items-center rounded border border-gray-300 p-1 dark:border-gray-600">' . $shape . '</span><span><span class="block text-xs text-gray-500">' . $groupLabel . ' · ' . $previewLabel . '</span><span class="block font-medium">' . $label . '</span><span class="block text-xs text-gray-500">' . $purpose . '</span>' . $requirementsText . $addedText . '</span></div>');
    }

    /** @return array<string, string> */
    private function workFirstLanguages(): array
    {
        $actor = auth()->user();
        if ($actor === null) {
            return [];
        }

        $siteId = $this->workFirstSiteFilter();
        $sites = SiteAccess::forActor($actor, acrossAssignedSites: true)->query(Site::class)->enabled()
            ->with(['language', 'siteDomains.language'])
            ->when($siteId !== null, fn (Builder $query): Builder => $query->whereKey($siteId))
            ->get();
        $languages = [];
        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                if ($language instanceof Language && $language->isEnabled()) {
                    $languages[$language->code] = $language->name;
                }
            }
        }

        asort($languages);

        return $languages;
    }

    private function workFirstSiteFilter(): ?int
    {
        $value = is_array($this->filters) ? ($this->filters['site_id'] ?? null) : null;
        if ($value === null || $value === '') {
            return null;
        }

        $siteId = filter_var($value, FILTER_VALIDATE_INT);

        return $siteId !== false && $siteId > 0 ? $siteId : -1;
    }

    private function allSitesSelected(): bool
    {
        return $this->workFirstSiteFilter() === null;
    }

    /** @return list<class-string<Widget>> */
    private function defaultWorkFirstWidgets(): array
    {
        return [
            UrgentSummaryWidget::class,
            PersonalDraftsWidget::class,
            ApprovalsWidget::class,
            AttentionWidget::class,
            PageViewsWidget::class,
            PublishingHealthWidget::class,
            PopularPagesWidget::class,
            RisingPagesWidget::class,
            TeamActivityWidget::class,
        ];
    }

    private function workFirstEnabled(): bool
    {
        return static::class === self::class
            && config('capell-admin.dashboard.work_first.enabled', false) === true;
    }

    private function reportsAction(): ?ActionGroup
    {
        $actions = [];
        foreach ([AnalyticsDashboard::class, DashboardToolsPage::class] as $page) {
            if ($page::canAccess()) {
                $actions[] = Action::make(class_basename($page))->label($page::getNavigationLabel())->url($page::getUrl());
            }
        }

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
        if ($this->workFirstEnabled()) {
            return DashboardDateRangeEnum::Last30Days->value;
        }

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
                if (! $this->workFirstClassEnabled($widgetClass)) {
                    return false;
                }

                if ($this->workFirstEnabled() && in_array($widgetClass, [NeedsAttentionFilamentWidget::class, RecentlyPublishedFilamentWidget::class, RecentActivityFilamentWidget::class, CapellAccountFilamentWidget::class, CapellInfoFilamentWidget::class, ListPagesFilamentWidget::class, AnalyticsOverviewFilamentWidget::class, AnalyticsTrendFilamentWidget::class, AnalyticsInsightsFilamentWidget::class, AnalyticsRecentActivityFilamentWidget::class], true)) {
                    return false;
                }

                $widgetSwitches = config('capell-admin.dashboard.work_first.widgets', []);
                if ($this->workFirstEnabled() && is_array($widgetSwitches)
                    && (($widgetClass === PersonalDraftsWidget::class && ($widgetSwitches['your_work'] ?? true) === false)
                        || ($widgetClass === ApprovalsWidget::class && ($widgetSwitches['approvals'] ?? true) === false)
                        || ($widgetClass === UrgentSummaryWidget::class && ($widgetSwitches['urgent_summary'] ?? true) === false)
                        || ($widgetClass === AttentionWidget::class && ($widgetSwitches['attention'] ?? true) === false)
                        || ($widgetClass === PublishingHealthWidget::class && ($widgetSwitches['publishing_health'] ?? true) === false)
                        || ($widgetClass === TeamActivityWidget::class && ($widgetSwitches['team_activity'] ?? true) === false)
                        || ($widgetClass === ContentStatusWidget::class && ($widgetSwitches['content_status'] ?? true) === false)
                        || ($widgetClass === RecentlyUpdatedWidget::class && ($widgetSwitches['recently_updated'] ?? true) === false)
                        || ($widgetClass === PageViewsWidget::class && ($widgetSwitches['page_views'] ?? true) === false)
                        || ($widgetClass === PopularPagesWidget::class && ($widgetSwitches['popular_pages'] ?? true) === false)
                        || ($widgetClass === RisingPagesWidget::class && ($widgetSwitches['rising_pages'] ?? true) === false))) {
                    return false;
                }

                if (! method_exists($widgetClass, 'settingsKey')) {
                    return true;
                }

                $settingsKey = $widgetClass::settingsKey();

                if ($this->workFirstEnabled() && is_string($settingsKey) && is_array($widgetSwitches) && ($widgetSwitches[$settingsKey] ?? true) === false) {
                    return false;
                }

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
