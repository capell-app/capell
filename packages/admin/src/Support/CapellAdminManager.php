<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Actions\UserMenu\ResolveUserMenuItemsAction;
use Capell\Admin\Concerns\HasAdminAssets;
use Capell\Admin\Concerns\HasEvents;
use Capell\Admin\Concerns\HasMigrations;
use Capell\Admin\Concerns\HasNavigation;
use Capell\Admin\Concerns\HasPaletteCommands;
use Capell\Admin\Concerns\HasWelcomeTours;
use Capell\Admin\Concerns\HasWidgets;
use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Data\Dashboard\CapellOverviewStatDefinitionData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Core\Facades\CapellCore;
use Closure;
use Exception;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use ReflectionClass;
use RuntimeException;
use Throwable;

class CapellAdminManager
{
    use HasAdminAssets;
    use HasEvents;
    use HasMigrations;
    use HasNavigation;
    use HasPaletteCommands;
    use HasWelcomeTours;
    use HasWidgets;
    use Macroable;

    private AdminSurfaceContributionRegistry $adminSurfaceRegistry;

    private AdminBridgeRegistry $adminBridgeRegistry;

    private ReportRegistry $reportRegistry;

    /** @var array<string, true> */
    private array $bootedAdminBridges = [];

    /** @var array<string, list<class-string<Widget>>> */
    private array $filamentDashboardWidgets = [];

    /** @var array<string, UserMenuItemData> */
    private array $userMenuItems = [];

    /** @var array<string, array<string, Action>> */
    private array $resolvedUserMenuItems = [];

    /** @var array<string, CapellOverviewStatDefinitionData> */
    private array $overviewStats = [];

    /** @var array<string, MarketingStudioActionData> */
    private array $marketingStudioActions = [];

    /** @var class-string<Page> */
    private string $dashboardPage = CapellDashboard::class;

    public function __construct()
    {
        $this->adminSurfaceRegistry = new AdminSurfaceContributionRegistry;
        $this->adminBridgeRegistry = new AdminBridgeRegistry;
        $this->reportRegistry = new ReportRegistry;
    }

    /** @return list<string>|list<FilamentWidgetEnum> */
    public function getWidgets(null|bool|Closure $filter = null): array
    {
        if (! CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()) {
            return [];
        }

        try {
            $widgets = FilamentWidgetEnum::cases();

            if ($filter === null) {
                return array_map(fn (FilamentWidgetEnum $widget): string => $widget->value, $widgets);
            }

            $dashboardSettings = resolve(AdminSettings::class);

            if (is_callable($filter)) {
                return array_values(array_filter($widgets, $filter));
            }

            return array_values(array_filter($widgets, fn (FilamentWidgetEnum $widget): bool => $dashboardSettings->isWidgetEnabled($widget->value) === $filter));
        } catch (Exception) {
            // Settings table may not exist yet during bootstrap
            // Return all widgets
            return array_map(fn (FilamentWidgetEnum $widgetEnum): string => $widgetEnum->value, FilamentWidgetEnum::cases());
        }
    }

    /**
     * Set which widgets should be enabled on the dashboard.
     *
     * @param  array<string|FilamentWidgetEnum>  $widgets
     */
    public function setEnabledWidgets(array $widgets): void
    {
        $dashboardSettings = resolve(AdminSettings::class);
        $enabledWidgets = [];

        foreach ($widgets as $widget) {
            $widgetClass = $widget instanceof FilamentWidgetEnum ? $widget->value : $widget;
            $enabledWidgets[$widgetClass] = true;
        }

        $dashboardSettings->enabled_widgets = $enabledWidgets;
        $dashboardSettings->save();
    }

    /**
     * @param  class-string<Widget>  $widgetClass
     */
    public function registerDashboardFilamentWidget(string $widgetClass, DashboardEnum ...$dashboards): void
    {
        foreach ($dashboards as $dashboard) {
            if (! in_array($widgetClass, $this->filamentDashboardWidgets[$dashboard->value] ?? [], true)) {
                $this->filamentDashboardWidgets[$dashboard->value][] = $widgetClass;
            }
        }
    }

    public function registerMarketingStudioAction(MarketingStudioActionData $action): void
    {
        if ($action->key === '') {
            return;
        }

        $this->marketingStudioActions[$action->key] = $action;
    }

    /**
     * @return array<string, list<MarketingStudioActionData>>
     */
    public function getMarketingStudioActions(): array
    {
        return collect($this->marketingStudioActions)
            ->filter(fn (MarketingStudioActionData $action): bool => $action->isVisible())
            ->sortBy([
                fn (MarketingStudioActionData $action): int => $action->section->caseOrdinal(),
                fn (MarketingStudioActionData $action): int => $action->sort,
                fn (MarketingStudioActionData $action): string => $action->resolvedLabel(),
            ])
            ->groupBy(fn (MarketingStudioActionData $action): string => $action->section->value)
            ->map(fn (Collection $actions): array => array_values($actions->values()->all()))
            ->all();
    }

    public function registerUserMenuItem(
        string $key,
        string|Closure $label,
        string|Heroicon|null $icon = null,
        string|Closure|null $url = null,
        int|string|Closure|null $badge = null,
        string|Closure|null $badgeColor = null,
        bool|Closure $visible = true,
        int $sort = 100,
        ?string $group = null,
    ): void {
        if ($key === '') {
            return;
        }

        $this->resolvedUserMenuItems = [];

        $this->userMenuItems[$key] = new UserMenuItemData(
            key: $key,
            label: $label,
            icon: $icon,
            url: $url,
            badge: $badge,
            badgeColor: $badgeColor,
            visible: $visible,
            sort: $sort,
            group: $group,
        );
    }

    /** @return array<string, UserMenuItemData> */
    public function getUserMenuItemDefinitions(): array
    {
        return $this->userMenuItems;
    }

    /** @return array<string, Action> */
    public function getUserMenuItems(?Authenticatable $user = null): array
    {
        $user ??= auth()->user();
        $cacheKey = $this->userMenuCacheKey($user);

        if (isset($this->resolvedUserMenuItems[$cacheKey])) {
            return $this->resolvedUserMenuItems[$cacheKey];
        }

        $this->resolvedUserMenuItems[$cacheKey] = ResolveUserMenuItemsAction::run(
            definitions: $this->userMenuItems,
            user: $user,
        );

        return $this->resolvedUserMenuItems[$cacheKey];
    }

    public function clearUserMenuItems(): void
    {
        $this->userMenuItems = [];
        $this->resolvedUserMenuItems = [];
    }

    public function registerOverviewStat(
        string $key,
        string|Closure $label,
        int|string|Closure $value,
        string|Closure $group = 'Core',
        null|string|Closure $description = null,
        null|string|Closure $url = null,
        ?string $color = null,
        int $sort = 100,
        bool $defaultEnabled = false,
        ?string $settingsKey = null,
        null|string|Closure $settingsLabel = null,
        null|string|Closure $settingsDescription = null,
    ): void {
        if ($key === '' || isset($this->overviewStats[$key])) {
            return;
        }

        $this->overviewStats[$key] = new CapellOverviewStatDefinitionData(
            key: $key,
            label: $label,
            value: $value,
            group: $group,
            description: $description,
            url: $url,
            color: $color,
            sort: $sort,
            defaultEnabled: $defaultEnabled,
            settingsKey: $settingsKey,
            settingsLabel: $settingsLabel,
            settingsDescription: $settingsDescription,
        );
    }

    /** @return list<CapellOverviewStatData> */
    public function getOverviewStats(bool $onlyEnabled = true): array
    {
        return array_values(collect($this->overviewStats)
            ->filter(fn (CapellOverviewStatDefinitionData $stat): bool => ! $onlyEnabled || $this->isOverviewStatEnabled($stat))
            ->map(fn (CapellOverviewStatDefinitionData $stat): CapellOverviewStatData => $stat->resolve())
            ->sortBy([
                ['sort', 'asc'],
                ['group', 'asc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all());
    }

    /**
     * @return list<array{key: string, label: string, group: string, description?: string|null}>
     */
    public function getOverviewStatSettings(): array
    {
        return array_values(collect($this->overviewStats)
            ->map(fn (CapellOverviewStatDefinitionData $stat): array => $stat->settingsEntry())
            ->values()
            ->all());
    }

    /** @return list<string> */
    public function getDefaultEnabledOverviewStatKeys(): array
    {
        return array_values(collect($this->overviewStats)
            ->filter(fn (CapellOverviewStatDefinitionData $stat): bool => $stat->defaultEnabled)
            ->map(fn (CapellOverviewStatDefinitionData $stat): string => $stat->settingsKey())
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    public function getOverviewStatKeys(): array
    {
        return array_values(collect($this->overviewStats)
            ->map(fn (CapellOverviewStatDefinitionData $stat): string => $stat->settingsKey())
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<class-string<Widget>> */
    public function getDashboardFilamentWidgets(DashboardEnum $dashboard): array
    {
        return array_values(collect($this->filamentDashboardWidgets[$dashboard->value] ?? [])
            ->sort(fn (string $firstWidgetClass, string $secondWidgetClass): int => $this->compareDashboardFilamentWidgets(
                $firstWidgetClass,
                $secondWidgetClass,
            ))
            ->values()
            ->all());
    }

    /**
     * @param  class-string<Page>  $pageClass
     */
    public function useDashboardPage(string $pageClass): void
    {
        $this->dashboardPage = $pageClass;
    }

    /**
     * @return class-string<Page>
     */
    public function getDashboardPage(): string
    {
        return $this->dashboardPage;
    }

    public function contributeToAdminSurface(AdminSurfaceContributionData $contribution): void
    {
        $this->adminSurfaceRegistry->register($contribution);
    }

    /**
     * @param  class-string<Page>  $page
     */
    public function registerExtensionPage(string $packageName, string $page): void
    {
        $this->contributeToAdminSurface(AdminSurfaceContributionData::page($page));
        $this->suppressExtensionPageNativeNavigation($page);

        resolve(ExtensionPageRegistry::class)->register($packageName, $page);
    }

    public function registerExtensionManagementSurface(ExtensionManagementSurfaceData $surface): void
    {
        resolve(ExtensionManagementSurfaceRegistry::class)->register($surface);
    }

    public function registerReport(ReportDefinitionData $report): void
    {
        $this->reportRegistry->register($report);
        $this->contributeToAdminSurface(AdminSurfaceContributionData::page($report->pageClass));
    }

    public function getReport(string $key): ?ReportDefinitionData
    {
        return $this->reportRegistry->get($key);
    }

    /** @return array<string, ReportDefinitionData> */
    public function getReports(): array
    {
        return $this->reportRegistry->all();
    }

    /** @return list<class-string> */
    public function getReportPages(): array
    {
        return $this->reportRegistry->pageClasses();
    }

    public function getReportRegistry(): ReportRegistry
    {
        return $this->reportRegistry;
    }

    public function getAdminSurfaceRegistry(): AdminSurfaceContributionRegistry
    {
        return $this->adminSurfaceRegistry;
    }

    /**
     * @param  class-string<AdminBridge>  $bridgeClass
     */
    public function registerAdminBridge(string $packageName, string $bridgeClass): void
    {
        $this->adminBridgeRegistry->register($packageName, $bridgeClass);
    }

    public function getAdminBridgeRegistry(): AdminBridgeRegistry
    {
        return $this->adminBridgeRegistry;
    }

    public function bootAdminBridges(string $packageName): void
    {
        $context = AdminBridgeContextData::forPackage($packageName);
        $registrar = resolve(AdminBridgeRegistrar::class);

        foreach ($this->adminBridgeRegistry->enabledBridges($context) as $bridge) {
            $bootKey = $packageName . ':' . $bridge::class;

            if (isset($this->bootedAdminBridges[$bootKey])) {
                continue;
            }

            $bridge->register($registrar, $context);

            $this->bootedAdminBridges[$bootKey] = true;
        }
    }

    public function hasResource(string $group, string $name = 'default'): bool
    {
        return isset($this->adminSurfaceRegistry->resourcesForGroup($group)[$name]);
    }

    public function getResource(string $group, string $name = 'default'): ?string
    {
        return $this->adminSurfaceRegistry->resourcesForGroup($group)[$name] ?? null;
    }

    /**
     * @param  class-string<Model>  $subjectClass
     * @param  class-string<FilamentResource>|null  $resourceClass
     */
    public function registerActivityResourceLink(
        string $subjectClass,
        ?string $resourceClass = null,
        ?string $relation = null,
        ?Closure $recordResolver = null,
    ): void {
        resolve(ActivityResourceLinkRegistry::class)->register(
            subjectClass: $subjectClass,
            resourceClass: $resourceClass,
            relation: $relation,
            recordResolver: $recordResolver,
        );
    }

    public function clearActivityResourceLinks(): void
    {
        resolve(ActivityResourceLinkRegistry::class)->clear();
    }

    /** @return array<string, class-string> */
    public function getConfigurators(string $group): array
    {
        return $this->adminSurfaceRegistry->configuratorsForGroup($group);
    }

    public function cacheConfigurators(): void
    {
        $filesystem = resolve(Filesystem::class);
        $cachePath = $this->getConfiguratorCachePath();
        $cacheDirectory = dirname($cachePath);

        if (! $filesystem->isDirectory($cacheDirectory)) {
            $filesystem->makeDirectory($cacheDirectory, 0755, true);
        }

        $filesystem->put($cachePath, '<?php return ' . var_export($this->serializableContributions(), true) . ';' . PHP_EOL);

    }

    public function clearCachedConfigurators(): void
    {
        resolve(Filesystem::class)->delete($this->getConfiguratorCachePath());

    }

    public function hasCachedConfigurators(): bool
    {
        return resolve(Filesystem::class)->exists($this->getConfiguratorCachePath());
    }

    public function getConfiguratorCachePath(): string
    {
        return app()->bootstrapPath('cache/capell-admin-configurators.php');
    }

    public function restoreCachedConfigurators(): void
    {
        if (! $this->hasCachedConfigurators()) {
            return;
        }

        /** @var array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>> $cachedContributions */
        $cachedContributions = require $this->getConfiguratorCachePath();
        $contributions = $this->hydrateContributions($cachedContributions);

        $this->adminSurfaceRegistry->clear();

        foreach ($contributions as $groupedContributions) {
            foreach ($groupedContributions as $contribution) {
                $this->adminSurfaceRegistry->register($contribution);
            }
        }

    }

    /**
     * @return array<string, AdminSurfaceContributionData>|array<string, array<string, AdminSurfaceContributionData>>
     */
    public function getAdminSurfaceContributions(?AdminSurfaceContributionType $type = null): array
    {
        $contributions = $this->adminSurfaceRegistry->all();

        if (! $type instanceof AdminSurfaceContributionType) {
            return $contributions;
        }

        return $contributions[$type->value] ?? [];
    }

    public function clearAdminSurfaceContributions(): void
    {
        $this->adminSurfaceRegistry->clear();
    }

    public function settings(): AdminSettings
    {
        $settingsClass = CapellCore::getPackage(AdminServiceProvider::$packageName)->setting;

        throw_if(! is_string($settingsClass) || $settingsClass === '', RuntimeException::class, 'Admin settings class is not configured.');

        return resolve($settingsClass);
    }

    private function userMenuCacheKey(?Authenticatable $user): string
    {
        if (! $user instanceof Authenticatable) {
            return 'guest';
        }

        return (string) $user->getAuthIdentifier();
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function suppressExtensionPageNativeNavigation(string $page): void
    {
        if ($page === ExtensionsPage::class) {
            return;
        }

        try {
            $reflectionClass = new ReflectionClass($page);

            if (! $reflectionClass->hasProperty('shouldRegisterNavigation')) {
                return;
            }

            $reflectionProperty = $reflectionClass->getProperty('shouldRegisterNavigation');

            if (! $reflectionProperty->isStatic()) {
                return;
            }

            $reflectionProperty->setValue(null, false);
        } catch (Throwable) {
            return;
        }
    }

    private function compareDashboardFilamentWidgets(string $firstWidgetClass, string $secondWidgetClass): int
    {
        $pinnedComparison = $this->comparePinnedDashboardFilamentWidgets($firstWidgetClass, $secondWidgetClass);

        if ($pinnedComparison !== null) {
            return $pinnedComparison;
        }

        $firstConfiguredSort = $this->configuredDashboardFilamentWidgetSort($firstWidgetClass);
        $secondConfiguredSort = $this->configuredDashboardFilamentWidgetSort($secondWidgetClass);

        if ($firstConfiguredSort !== null && $secondConfiguredSort !== null) {
            $configuredComparison = $firstConfiguredSort <=> $secondConfiguredSort;

            if ($configuredComparison !== 0) {
                return $configuredComparison;
            }

            return $this->compareDashboardFilamentWidgetDefaults($firstWidgetClass, $secondWidgetClass);
        }

        if ($firstConfiguredSort !== null) {
            return -1;
        }

        if ($secondConfiguredSort !== null) {
            return 1;
        }

        return $this->compareDashboardFilamentWidgetDefaults($firstWidgetClass, $secondWidgetClass);
    }

    private function comparePinnedDashboardFilamentWidgets(string $firstWidgetClass, string $secondWidgetClass): ?int
    {
        $firstDefaultSort = $this->filamentDashboardWidgetDefaultSort($firstWidgetClass);
        $secondDefaultSort = $this->filamentDashboardWidgetDefaultSort($secondWidgetClass);
        $firstIsPinned = $firstDefaultSort < 0;
        $secondIsPinned = $secondDefaultSort < 0;

        if (! $firstIsPinned && ! $secondIsPinned) {
            return null;
        }

        if ($firstIsPinned !== $secondIsPinned) {
            return $firstIsPinned ? -1 : 1;
        }

        $defaultComparison = $firstDefaultSort <=> $secondDefaultSort;

        if ($defaultComparison !== 0) {
            return $defaultComparison;
        }

        return $firstWidgetClass <=> $secondWidgetClass;
    }

    private function compareDashboardFilamentWidgetDefaults(string $firstWidgetClass, string $secondWidgetClass): int
    {
        $defaultComparison = $this->filamentDashboardWidgetDefaultSort($firstWidgetClass) <=> $this->filamentDashboardWidgetDefaultSort($secondWidgetClass);

        if ($defaultComparison !== 0) {
            return $defaultComparison;
        }

        return $firstWidgetClass <=> $secondWidgetClass;
    }

    private function configuredDashboardFilamentWidgetSort(string $widgetClass): ?int
    {
        $settingsKey = $this->filamentDashboardWidgetSettingsKey($widgetClass);

        if ($settingsKey === '') {
            return null;
        }

        try {
            $settings = resolve(AdminSettings::class);
        } catch (Throwable) {
            return null;
        }

        if (! array_key_exists($settingsKey, $settings->widget_order)) {
            return null;
        }

        return $settings->sortOrderFor($settingsKey);
    }

    private function filamentDashboardWidgetDefaultSort(string $widgetClass): int
    {
        if (is_callable([$widgetClass, 'getSort'])) {
            $sort = forward_static_call([$widgetClass, 'getSort']);

            return is_int($sort) ? $sort : PHP_INT_MAX;
        }

        return PHP_INT_MAX;
    }

    private function filamentDashboardWidgetSettingsKey(string $widgetClass): string
    {
        if (! class_exists($widgetClass) || ! method_exists($widgetClass, 'settingsKey')) {
            return '';
        }

        try {
            $settingsKey = $widgetClass::settingsKey();
        } catch (Throwable) {
            return '';
        }

        return is_string($settingsKey) ? $settingsKey : '';
    }

    private function isOverviewStatEnabled(CapellOverviewStatDefinitionData $stat): bool
    {
        try {
            return resolve(AdminSettings::class)->isWidgetEnabled($stat->settingsKey());
        } catch (Throwable) {
            return $stat->defaultEnabled;
        }
    }

    /** @return array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>> */
    private function serializableContributions(): array
    {
        return array_map(
            static fn (array $groupedContributions): array => array_map(
                static fn (AdminSurfaceContributionData $contribution): array => [
                    'type' => $contribution->type->value,
                    'class' => $contribution->class,
                    'key' => $contribution->key,
                    'group' => $contribution->group,
                    'name' => $contribution->name,
                    'tag' => $contribution->tag,
                ],
                $groupedContributions,
            ),
            $this->adminSurfaceRegistry->all(),
        );
    }

    /**
     * @param  array<string, array<string, array{type: string, class: string, key: string, group: string|null, name: string, tag: string|null}>>  $cachedContributions
     * @return array<string, array<string, AdminSurfaceContributionData>>
     */
    private function hydrateContributions(array $cachedContributions): array
    {
        return array_map(
            static fn (array $groupedContributions): array => array_map(
                static fn (array $contribution): AdminSurfaceContributionData => new AdminSurfaceContributionData(
                    type: AdminSurfaceContributionType::from($contribution['type']),
                    class: $contribution['class'],
                    key: $contribution['key'],
                    group: $contribution['group'],
                    name: $contribution['name'],
                    tag: $contribution['tag'],
                ),
                $groupedContributions,
            ),
            $cachedContributions,
        );
    }
}
