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
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
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
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;
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

    /** @var array<string, true> */
    private array $bootedAdminBridges = [];

    /** @var class-string<Page> */
    private string $dashboardPage = CapellDashboard::class;

    private readonly AdminSurfaceContributionRegistry $adminSurfaceRegistry;

    private readonly ReportRegistry $reportRegistry;

    private readonly DashboardFilamentWidgetRegistry $dashboardWidgetRegistry;

    private readonly MarketingStudioActionRegistry $marketingStudioActionRegistry;

    private readonly UserMenuItemRegistry $userMenuItemRegistry;

    private readonly OverviewStatRegistry $overviewStatRegistry;

    public function __construct(
        ?AdminSurfaceContributionRegistry $adminSurfaceRegistry = null,
        ?ReportRegistry $reportRegistry = null,
        ?DashboardFilamentWidgetRegistry $dashboardWidgetRegistry = null,
        ?MarketingStudioActionRegistry $marketingStudioActionRegistry = null,
        ?UserMenuItemRegistry $userMenuItemRegistry = null,
        ?OverviewStatRegistry $overviewStatRegistry = null,
    ) {
        $this->adminSurfaceRegistry = $adminSurfaceRegistry ?? new AdminSurfaceContributionRegistry;
        $this->reportRegistry = $reportRegistry ?? new ReportRegistry;
        $this->dashboardWidgetRegistry = $dashboardWidgetRegistry ?? new DashboardFilamentWidgetRegistry;
        $this->marketingStudioActionRegistry = $marketingStudioActionRegistry ?? new MarketingStudioActionRegistry;
        $this->userMenuItemRegistry = $userMenuItemRegistry ?? new UserMenuItemRegistry;
        $this->overviewStatRegistry = $overviewStatRegistry ?? new OverviewStatRegistry;
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
        $this->dashboardWidgetRegistry->register($widgetClass, ...$dashboards);
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
        return $this->userMenuItemRegistry->definitions();
    }

    /** @return array<string, Action> */
    public function getUserMenuItems(?Authenticatable $user = null): array
    {
        $user ??= auth()->user();

        return $this->userMenuItemRegistry->resolved($user);
    }

    public function clearUserMenuItems(): void
    {
        $this->userMenuItemRegistry->clear();
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
        return $this->overviewStatRegistry->resolved($onlyEnabled);
    }

    /**
     * @return list<array{key: string, label: string, group: string, description?: string|null}>
     */
    public function getOverviewStatSettings(): array
    {
        return $this->overviewStatRegistry->settings();
    }

    /** @return list<string> */
    public function getDefaultEnabledOverviewStatKeys(): array
    {
        return $this->overviewStatRegistry->defaultEnabledKeys();
    }

    /** @return list<string> */
    public function getOverviewStatKeys(): array
    {
        return $this->overviewStatRegistry->keys();
    }

    /** @return list<class-string<Widget>> */
    public function getDashboardFilamentWidgets(DashboardEnum $dashboard): array
    {
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
        resolve(AdminBridgeRegistrar::class)->bridge($packageName, $bridgeClass);
    }

    public function bootAdminBridges(string $packageName): void
    {
        $context = AdminBridgeContextData::forPackage($packageName);
        $registrar = resolve(AdminBridgeRegistrar::class);
        $registry = resolve(AdminBridgeRegistry::class);

        foreach ($registry->enabledBridges($context) as $bridge) {
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

    /** @return class-string|null */
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
