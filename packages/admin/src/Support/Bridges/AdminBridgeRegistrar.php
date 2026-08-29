<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Contracts\Extensions\ExtensionDependencyProvider;
use Capell\Admin\Contracts\Extensions\ExtensionHealthProvider;
use Capell\Admin\Contracts\Extensions\ExtensionQuickActionProvider;
use Capell\Admin\Contracts\Extensions\ExtensionRemovalCoordinator;
use Capell\Admin\Contracts\Extensions\ExtensionRuntimeCheckProvider;
use Capell\Admin\Contracts\Extensions\ExtensionUpdateMetadataProvider;
use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\AdminWorkspaceItemData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use ReflectionFunction;

final class AdminBridgeRegistrar
{
    public function __construct(
        private readonly AdminBridgeRegistry $bridges,
        private readonly SettingsSchemaRegistry $settings,
        private readonly RecordsExtensionContributionReceipt $receipts,
    ) {}

    /**
     * @param  class-string<AdminBridge>  $bridgeClass
     */
    public function bridge(string $packageName, string $bridgeClass): void
    {
        $this->bridges->register($packageName, $bridgeClass);
        $this->receipt(ExtensionContributionType::AdminPage, 'bridge:' . $packageName . ':' . $bridgeClass, $bridgeClass);
    }

    /** @param class-string $pageClass */
    public function page(string $pageClass): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page($pageClass));
    }

    public function report(ReportDefinitionData $report): void
    {
        CapellAdmin::registerReport($report);
    }

    /** @param class-string<Page> $pageClass */
    public function dashboardPage(string $pageClass): void
    {
        CapellAdmin::useDashboardPage($pageClass);
        $this->receipt(ExtensionContributionType::AdminPage, 'dashboard-page:' . $pageClass, $pageClass);
    }

    /** @param class-string $resourceClass */
    public function resource(string $resourceClass, string $group, string $name = 'default'): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource($resourceClass, $group, $name));
    }

    /** @param class-string $widgetClass */
    public function widget(string $widgetClass): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::widget($widgetClass));
    }

    /** @param class-string $widgetClass */
    public function filamentDashboardWidget(string $widgetClass, DashboardEnum ...$dashboards): void
    {
        if (is_subclass_of($widgetClass, Widget::class)) {
            CapellAdmin::registerDashboardFilamentWidget($widgetClass, ...$dashboards);
            $this->receipt(ExtensionContributionType::DashboardFilamentWidget, 'dashboard-widget:' . $widgetClass, $widgetClass);
        }
    }

    /** @param class-string $widgetClass */
    public function dashboardPanel(DashboardRegionEnum $region, string $widgetClass, DashboardEnum ...$dashboards): void
    {
        if (is_subclass_of($widgetClass, Widget::class)) {
            CapellAdmin::registerDashboardPanel($region, $widgetClass, ...$dashboards);
            $this->receipt(ExtensionContributionType::DashboardFilamentWidget, 'dashboard-panel:' . $region->value . ':' . $widgetClass, $widgetClass);
        }
    }

    /** @param class-string $widgetClass */
    public function extensionDashboardFilamentWidget(string $widgetClass): void
    {
        if (is_subclass_of($widgetClass, Widget::class)) {
            CapellAdmin::registerDashboardFilamentWidget($widgetClass, DashboardEnum::Extensions);
            $this->receipt(ExtensionContributionType::DashboardFilamentWidget, 'extensions-dashboard-widget:' . $widgetClass, $widgetClass);
        }
    }

    /** @param class-string<ExtensionHealthProvider> $providerClass */
    public function extensionHealthProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionHealthProvider::TAG);
        $this->receipt(ExtensionContributionType::HealthCheck, 'extension-health-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ExtensionRuntimeCheckProvider> $providerClass */
    public function extensionRuntimeCheckProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionRuntimeCheckProvider::TAG);
        $this->receipt(ExtensionContributionType::HealthCheck, 'extension-runtime-check-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ExtensionQuickActionProvider> $providerClass */
    public function extensionQuickActionProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionQuickActionProvider::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extension-quick-action-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ExtensionUpdateMetadataProvider> $providerClass */
    public function extensionUpdateMetadataProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionUpdateMetadataProvider::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extension-update-metadata-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ExtensionDependencyProvider> $providerClass */
    public function extensionDependencyProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionDependencyProvider::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extension-dependency-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ExtensionsPageExtender> $extenderClass */
    public function extensionsPageExtender(string $extenderClass): void
    {
        app()->tag([$extenderClass], ExtensionsPageExtender::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extensions-page-extender:' . $extenderClass, $extenderClass);
    }

    /** @param class-string<ExtensionCatalogueMetadataProvider> $providerClass */
    public function extensionCatalogueMetadataProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionCatalogueMetadataProvider::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extension-catalogue-provider:' . $providerClass, $providerClass);
    }

    /** @param class-string<ResourceHeaderActionExtender> $extenderClass */
    public function resourceHeaderActionExtender(string $extenderClass): void
    {
        app()->tag([$extenderClass], ResourceHeaderActionExtender::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'resource-header-action-extender:' . $extenderClass, $extenderClass);
    }

    /**
     * Replace whatever performs extension removals on this site.
     *
     * A bind rather than a tag: unlike the extenders, there is exactly one
     * answer to "how does this site remove an extension", and two registrations
     * would mean two removals of the same package.
     *
     * @param  class-string<ExtensionRemovalCoordinator>  $coordinatorClass
     */
    public function extensionRemovalCoordinator(string $coordinatorClass): void
    {
        app()->bind(ExtensionRemovalCoordinator::class, $coordinatorClass);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extension-removal-coordinator', $coordinatorClass);
    }

    /** @param class-string<PendingThemeInstallProvider> $providerClass */
    public function pendingThemeInstallProvider(string $providerClass): void
    {
        app()->tag([$providerClass], PendingThemeInstallProvider::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'pending-theme-install-provider:' . $providerClass, $providerClass);
    }

    /** @param Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup) $action */
    public function extensionsPageHeaderAction(Action|ActionGroup|Closure $action, ?string $key = null): void
    {
        resolve(ExtensionsPageActionRegistry::class)->registerHeaderAction($action, $key);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extensions-page-header-action:' . $this->actionIdentity($action, $key), $this->implementation($action));
    }

    /** @param Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup) $action */
    public function extensionsPageHeaderActionGroupAction(Action|ActionGroup|Closure $action, ?string $key = null): void
    {
        resolve(ExtensionsPageActionRegistry::class)->registerHeaderActionGroupAction($action, $key);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extensions-page-header-group-action:' . $this->actionIdentity($action, $key), $this->implementation($action));
    }

    /** @param Action|Closure(ExtensionsPage): Action $action */
    public function extensionsPageTableAction(Action|Closure $action, ?string $key = null): void
    {
        resolve(ExtensionsPageActionRegistry::class)->registerTableAction($action, $key);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'extensions-page-table-action:' . $this->actionIdentity($action, $key), $this->implementation($action));
    }

    public function userMenuItem(
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
        CapellAdmin::registerUserMenuItem(
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
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'user-menu-item:' . $key, AdminWorkspaceItemData::class);
    }

    public function workspace(AdminWorkspaceItemData $item): void
    {
        CapellAdmin::registerWorkspace($item);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'workspace:' . $item->key, $item::class);
    }

    public function welcomeTourStep(
        string $key,
        string|Closure $title,
        string|Closure|HtmlString|View $description,
        ?string $element = null,
        ?string $icon = null,
        ?string $iconColor = null,
        int $sort = 100,
        bool|Closure $visible = true,
        ?string $chapter = 'dashboard',
        ?string $route = null,
    ): void {
        CapellAdmin::registerWelcomeTourStep(
            key: $key,
            title: $title,
            description: $description,
            element: $element,
            icon: $icon,
            iconColor: $iconColor,
            sort: $sort,
            visible: $visible,
            chapter: $chapter,
            route: $route,
        );
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'welcome-tour:' . $key, $this->implementation($title));
    }

    public function configurator(string $configuratorClass, string $group, string $name): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator($configuratorClass, $group, $name));
        $this->receipt(ExtensionContributionType::Configurator, 'configurator:' . $group . ':' . $name, $configuratorClass);
    }

    public function schemaExtender(string $extenderClass, string $tag): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::schemaExtender($extenderClass, $tag));
        app()->tag([$extenderClass], $tag);
        $this->receipt(ExtensionContributionType::SchemaExtender, 'schema-extender:' . $tag . ':' . $extenderClass, $extenderClass);
    }

    public function panelExtender(string $extenderClass): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::panelExtender($extenderClass));
        app()->tag([$extenderClass], AdminPanelExtender::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'panel-extender:' . $extenderClass, $extenderClass);
    }

    /** @param class-string<UserResourceBridge> $bridgeClass */
    public function userResourceBridge(string $bridgeClass, bool $scoped = true): void
    {
        if ($scoped) {
            app()->scoped($bridgeClass);
        }

        app()->tag([$bridgeClass], UserResourceBridge::TAG);
        $this->receipt(ExtensionContributionType::AdminResource, 'user-resource-bridge:' . $bridgeClass, $bridgeClass);
    }

    /**
     * @param  class-string<DashboardSettingsContributor>  $contributorClass
     */
    public function dashboardSettingsContributor(string $contributorClass): void
    {
        app()->tag([$contributorClass], DashboardSettingsContributor::TAG);
        $this->receipt(ExtensionContributionType::Setting, 'dashboard-settings-contributor:' . $contributorClass, $contributorClass);
    }

    /**
     * @param  class-string<Page>  $pageClass
     */
    public function extensionPage(string $packageName, string $pageClass): void
    {
        CapellAdmin::registerExtensionPage($packageName, $pageClass);
        $this->receipt(ExtensionContributionType::AdminPage, 'extension-page:' . $packageName . ':' . $pageClass, $pageClass);
    }

    public function extensionManagementSurface(ExtensionManagementSurfaceData $surface): void
    {
        CapellAdmin::registerExtensionManagementSurface($surface);
        $this->receipt(ExtensionContributionType::AdminPage, 'extension-management-surface:' . $surface->packageName . ':' . $surface->type . ':' . ($surface->settingsGroup ?? ''), $surface::class);
    }

    /**
     * @param  class-string<ActivityChangeSetBuilder>  $builderClass
     */
    public function activityChangeSetBuilder(string $builderClass): void
    {
        app()->tag([$builderClass], ActivityChangeSetBuilder::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'activity-change-set-builder:' . $builderClass, $builderClass);
    }

    /**
     * @param  class-string<ActivityRevertHandler>  $handlerClass
     */
    public function activityRevertHandler(string $handlerClass): void
    {
        app()->tag([$handlerClass], ActivityRevertHandler::TAG);
        $this->receipt(ExtensionContributionType::AdminActionExtender, 'activity-revert-handler:' . $handlerClass, $handlerClass);
    }

    /**
     * @param  class-string<Model>  $subjectClass
     * @param  class-string<FilamentResource>|null  $resourceClass
     */
    public function activityResourceLink(
        string $subjectClass,
        ?string $resourceClass = null,
        ?string $relation = null,
        ?Closure $recordResolver = null,
    ): void {
        CapellAdmin::registerActivityResourceLink(
            subjectClass: $subjectClass,
            resourceClass: $resourceClass,
            relation: $relation,
            recordResolver: $recordResolver,
        );
        $this->receipt(ExtensionContributionType::AdminResource, 'activity-resource-link:' . $subjectClass . ':' . ($resourceClass ?? 'default'), $resourceClass ?? $subjectClass);
    }

    /**
     * @param  class-string<HasSchema>  $schemaClass
     */
    public function settingsSchema(string $group, string $schemaClass, ?string $key = null): void
    {
        $this->settings->register($group, $schemaClass, $key);
        $this->receipt(ExtensionContributionType::Setting, 'settings-schema:' . $group . ':' . ($key ?? class_basename($schemaClass)), $schemaClass);
    }

    /**
     * @param  class-string<SettingsContract>  $settingsClass
     */
    public function settingsClass(string $group, string $settingsClass): void
    {
        $this->settings->registerSettingsClass($group, $settingsClass);
        $this->receipt(ExtensionContributionType::Setting, 'settings-class:' . $group, $settingsClass);
    }

    public function settingsMetadata(SettingsGroupMetadata $metadata): void
    {
        $this->settings->registerMetadata($metadata);
        $this->receipt(ExtensionContributionType::Setting, 'settings-metadata:' . $metadata->group, $metadata::class);
    }

    private function receipt(ExtensionContributionType $type, string $key, string $implementation): void
    {
        $this->receipts->recordContribution($type, $key, $implementation, self::class, 'admin');
    }

    private function implementation(mixed $value): string
    {
        return is_object($value) ? $value::class : get_debug_type($value);
    }

    private function actionIdentity(Action|ActionGroup|Closure $action, ?string $key): string
    {
        if ($key !== null && $key !== '') {
            return $key;
        }

        if ($action instanceof Action || $action instanceof ActionGroup) {
            return method_exists($action, 'getName') && is_string($action->getName())
                ? $action->getName()
                : $action::class;
        }

        $reflection = new ReflectionFunction($action);

        return 'legacy-' . hash('sha256', implode('|', [
            $reflection->getFileName() ?: 'unknown',
            (string) $reflection->getStartLine(),
            (string) $reflection->getEndLine(),
        ]));
    }
}
