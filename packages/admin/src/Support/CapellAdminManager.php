<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Concerns\HasAdminAssets;
use Capell\Admin\Concerns\HasEvents;
use Capell\Admin\Concerns\HasMigrations;
use Capell\Admin\Concerns\HasNavigation;
use Capell\Admin\Concerns\HasPaletteCommands;
use Capell\Admin\Concerns\HasWelcomeTours;
use Capell\Admin\Concerns\HasWidgets;
use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\AdminWorkspaceItemData;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Data\Dashboard\CapellOverviewStatDefinitionData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;
use Capell\Admin\Support\Workspace\AdminWorkspaceRegistry;
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
use ReflectionProperty;
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

    /** @var array<string, true> */
    private array $bootedAdminBridges = [];

    /**
     * Extension page classes whose native navigation has already been
     * suppressed. A page registered by more than one package (or re-registered
     * on an Octane worker that reuses this manager across requests) must not
     * pay the reflection cost twice for the same class.
     *
     * @var array<class-string<Page>, true>
     */
    private array $suppressedExtensionPageNavigation = [];

    /** @var class-string<Page> */
    private string $dashboardPage = CapellDashboard::class;

    public function __construct(
        private readonly AdminSurfaceContributionRegistry $adminSurfaceRegistry,
        private readonly ReportRegistry $reportRegistry,
        private readonly DashboardFilamentWidgetRegistry $dashboardWidgetRegistry,
        private readonly MarketingStudioActionRegistry $marketingStudioActionRegistry,
        private readonly UserMenuItemRegistry $userMenuItemRegistry,
        private readonly AdminWorkspaceRegistry $workspaceRegistry,
        private readonly OverviewStatRegistry $overviewStatRegistry,
        private readonly AdminSurfaceContributionCache $adminSurfaceCache,
    ) {}

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
        $this->dashboardWidgetRegistry->register($widgetClass, ...$dashboards);
    }

    /**
     * @param  class-string<Widget>  $widgetClass
     */
    public function registerDashboardPanel(DashboardRegionEnum $region, string $widgetClass, DashboardEnum ...$dashboards): void
    {
        $this->dashboardWidgetRegistry->registerPanel($region, $widgetClass, ...$dashboards);
    }

    /** @return list<class-string<Widget>> */
    public function getDashboardFilamentWidgetsByRegion(DashboardEnum $dashboard, DashboardRegionEnum $region): array
    {
        return $this->dashboardWidgetRegistry->forDashboardRegion($dashboard, $region);
    }

    public function registerMarketingStudioAction(MarketingStudioActionData $action): void
    {
        $this->marketingStudioActionRegistry->register($action);
    }

    /**
     * @return array<string, list<MarketingStudioActionData>>
     */
    public function getMarketingStudioActions(): array
    {
        $this->prepareAdminRuntime();

        return $this->marketingStudioActionRegistry->groupedVisibleActions();
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
        $this->userMenuItemRegistry->register(new UserMenuItemData(
            key: $key,
            label: $label,
            icon: $icon,
            url: $url,
            badge: $badge,
            badgeColor: $badgeColor,
            visible: $visible,
            sort: $sort,
            group: $group,
        ));
    }

    /** @return array<string, UserMenuItemData> */
    public function getUserMenuItemDefinitions(): array
    {
        $this->prepareAdminRuntime();

        return $this->userMenuItemRegistry->definitions();
    }

    /** @return array<string, Action> */
    public function getUserMenuItems(?Authenticatable $user = null): array
    {
        $this->prepareAdminRuntime();

        $user ??= auth()->user();

        return $this->userMenuItemRegistry->resolved($user);
    }

    public function clearUserMenuItems(): void
    {
        $this->prepareAdminRuntime();

        $this->userMenuItemRegistry->clear();
    }

    public function registerWorkspace(AdminWorkspaceItemData $item): void
    {
        $this->workspaceRegistry->register($item);
    }

    /** @return array<string, AdminWorkspaceItemData> */
    public function getWorkspaceDefinitions(): array
    {
        $this->prepareAdminRuntime();

        return $this->workspaceRegistry->definitions();
    }

    public function clearWorkspaces(): void
    {
        $this->workspaceRegistry->clear();
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
        $this->overviewStatRegistry->register(new CapellOverviewStatDefinitionData(
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
        ));
    }

    /** @return list<CapellOverviewStatData> */
    public function getOverviewStats(bool $onlyEnabled = true): array
    {
        $this->prepareAdminRuntime();

        return $this->overviewStatRegistry->resolved($onlyEnabled);
    }

    /**
     * @return list<array{key: string, label: string, group: string, description?: string|null}>
     */
    public function getOverviewStatSettings(): array
    {
        $this->prepareAdminRuntime();

        return $this->overviewStatRegistry->settings();
    }

    /** @return list<string> */
    public function getDefaultEnabledOverviewStatKeys(): array
    {
        $this->prepareAdminRuntime();

        return $this->overviewStatRegistry->defaultEnabledKeys();
    }

    /** @return list<string> */
    public function getOverviewStatKeys(): array
    {
        $this->prepareAdminRuntime();

        return $this->overviewStatRegistry->keys();
    }

    /** @return list<class-string<Widget>> */
    public function getDashboardFilamentWidgets(DashboardEnum $dashboard): array
    {
        $this->prepareAdminRuntime();

        return $this->dashboardWidgetRegistry->forDashboard($dashboard);
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
        $this->prepareAdminRuntime();

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
        $this->prepareAdminRuntime();

        return $this->reportRegistry->get($key);
    }

    /** @return array<string, ReportDefinitionData> */
    public function getReports(): array
    {
        $this->prepareAdminRuntime();

        return $this->reportRegistry->all();
    }

    /** @return list<class-string> */
    public function getReportPages(): array
    {
        $this->prepareAdminRuntime();

        return $this->reportRegistry->pageClasses();
    }

    public function getReportRegistry(): ReportRegistry
    {
        $this->prepareAdminRuntime();

        return $this->reportRegistry;
    }

    public function clearReports(): void
    {
        $this->prepareAdminRuntime();

        $this->reportRegistry->clear();
    }

    public function getAdminSurfaceRegistry(): AdminSurfaceContributionRegistry
    {
        $this->prepareAdminRuntime();

        return $this->adminSurfaceRegistry;
    }

    /**
     * @param  class-string<AdminBridge>  $bridgeClass
     */
    public function registerAdminBridge(string $packageName, string $bridgeClass): void
    {
        resolve(AdminBridgeRegistrar::class)->bridge($packageName, $bridgeClass);

        if (app()->resolved(AdminRuntimeActivator::class) && resolve(AdminRuntimeActivator::class)->isPrepared()) {
            resolve(AdminRuntimeActivator::class)->prepare();
        }
    }

    public function bootAdminBridges(string $packageName): void
    {
        $context = AdminBridgeContextData::forPackage($packageName);
        $registrar = resolve(AdminBridgeRegistrar::class);

        foreach (resolve(AdminBridgeRegistry::class)->enabledBridges($context) as $bridge) {
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
        $this->prepareAdminRuntime();

        return isset($this->adminSurfaceRegistry->resourcesForGroup($group)[$name]);
    }

    /** @return class-string|null */
    public function getResource(string $group, string $name = 'default'): ?string
    {
        $this->prepareAdminRuntime();

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
        $this->prepareAdminRuntime();

        resolve(ActivityResourceLinkRegistry::class)->clear();
    }

    /** @return array<string, class-string> */
    public function getConfigurators(string $group): array
    {
        $this->prepareAdminRuntime();

        return $this->adminSurfaceRegistry->configuratorsForGroup($group);
    }

    public function cacheConfigurators(): void
    {
        $this->adminSurfaceCache->cache();
    }

    public function clearCachedConfigurators(): void
    {
        $this->adminSurfaceCache->clear();
    }

    public function hasCachedConfigurators(): bool
    {
        return $this->adminSurfaceCache->exists();
    }

    public function getConfiguratorCachePath(): string
    {
        return $this->adminSurfaceCache->path();
    }

    public function restoreCachedConfigurators(): void
    {
        $this->adminSurfaceCache->restore();
    }

    /**
     * @return array<string, AdminSurfaceContributionData>|array<string, array<string, AdminSurfaceContributionData>>
     */
    public function getAdminSurfaceContributions(?AdminSurfaceContributionType $type = null): array
    {
        $this->prepareAdminRuntime();

        $contributions = $this->adminSurfaceRegistry->all();

        if (! $type instanceof AdminSurfaceContributionType) {
            return $contributions;
        }

        return $contributions[$type->value] ?? [];
    }

    public function clearAdminSurfaceContributions(): void
    {
        $this->prepareAdminRuntime();

        $this->adminSurfaceRegistry->clear();
    }

    public function settings(): AdminSettings
    {
        $settingsClass = CapellCore::getPackage(AdminServiceProvider::$packageName)->setting;

        throw_if(! is_string($settingsClass) || $settingsClass === '', RuntimeException::class, 'Admin settings class is not configured.');

        return resolve($settingsClass);
    }

    private function prepareAdminRuntime(): void
    {
        if (app()->bound(AdminRuntimeActivator::class)) {
            resolve(AdminRuntimeActivator::class)->prepare();
        }
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function suppressExtensionPageNativeNavigation(string $page): void
    {
        if ($page === ExtensionsPage::class || isset($this->suppressedExtensionPageNavigation[$page])) {
            return;
        }

        // A direct ReflectionProperty avoids constructing a ReflectionClass and
        // walking hasProperty()/getProperty()/isStatic() just to reach the one
        // property we need. The static property is protected, so a plain
        // "$page::$shouldRegisterNavigation = false" from outside the class is
        // not legal PHP; reflection remains required to bypass that visibility.
        // Both "property does not exist" and "property is not static" fail as
        // exceptions here (ReflectionException / TypeError respectively), which
        // the catch below turns into the same silent no-op the previous
        // hasProperty()/isStatic() guards produced.
        try {
            new ReflectionProperty($page, 'shouldRegisterNavigation')->setValue(null, false);
        } catch (Throwable) {
            return;
        }

        $this->suppressedExtensionPageNavigation[$page] = true;
    }
}
