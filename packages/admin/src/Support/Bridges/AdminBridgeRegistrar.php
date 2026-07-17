<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Contracts\Extensions\ExtensionDependencyProvider;
use Capell\Admin\Contracts\Extensions\ExtensionHealthProvider;
use Capell\Admin\Contracts\Extensions\ExtensionQuickActionProvider;
use Capell\Admin\Contracts\Extensions\ExtensionRuntimeCheckProvider;
use Capell\Admin\Contracts\Extensions\ExtensionUpdateMetadataProvider;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Closure;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

final class AdminBridgeRegistrar
{
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
        }
    }

    /** @param class-string $widgetClass */
    public function extensionDashboardFilamentWidget(string $widgetClass): void
    {
        if (is_subclass_of($widgetClass, Widget::class)) {
            CapellAdmin::registerDashboardFilamentWidget($widgetClass, DashboardEnum::Extensions);
        }
    }

    /** @param class-string<ExtensionHealthProvider> $providerClass */
    public function extensionHealthProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionHealthProvider::TAG);
    }

    /** @param class-string<ExtensionRuntimeCheckProvider> $providerClass */
    public function extensionRuntimeCheckProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionRuntimeCheckProvider::TAG);
    }

    /** @param class-string<ExtensionQuickActionProvider> $providerClass */
    public function extensionQuickActionProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionQuickActionProvider::TAG);
    }

    /** @param class-string<ExtensionUpdateMetadataProvider> $providerClass */
    public function extensionUpdateMetadataProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionUpdateMetadataProvider::TAG);
    }

    /** @param class-string<ExtensionDependencyProvider> $providerClass */
    public function extensionDependencyProvider(string $providerClass): void
    {
        app()->tag([$providerClass], ExtensionDependencyProvider::TAG);
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
        );
    }

    public function configurator(string $configuratorClass, string $group, string $name): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator($configuratorClass, $group, $name));
    }

    public function schemaExtender(string $extenderClass, string $tag): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::schemaExtender($extenderClass, $tag));
        app()->tag([$extenderClass], $tag);
    }

    public function panelExtender(string $extenderClass): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::panelExtender($extenderClass));
        app()->tag([$extenderClass], AdminPanelExtender::TAG);
    }

    /** @param class-string<UserResourceBridge> $bridgeClass */
    public function userResourceBridge(string $bridgeClass, bool $scoped = true): void
    {
        if ($scoped) {
            app()->scoped($bridgeClass);
        }

        app()->tag([$bridgeClass], UserResourceBridge::TAG);
    }

    /**
     * @param  class-string<DashboardSettingsContributor>  $contributorClass
     */
    public function dashboardSettingsContributor(string $contributorClass): void
    {
        app()->tag([$contributorClass], DashboardSettingsContributor::TAG);
    }

    /**
     * @param  class-string<Page>  $pageClass
     */
    public function extensionPage(string $packageName, string $pageClass): void
    {
        CapellAdmin::registerExtensionPage($packageName, $pageClass);
    }

    public function extensionManagementSurface(ExtensionManagementSurfaceData $surface): void
    {
        CapellAdmin::registerExtensionManagementSurface($surface);
    }

    /**
     * @param  class-string<ActivityChangeSetBuilder>  $builderClass
     */
    public function activityChangeSetBuilder(string $builderClass): void
    {
        app()->tag([$builderClass], ActivityChangeSetBuilder::TAG);
    }

    /**
     * @param  class-string<ActivityRevertHandler>  $handlerClass
     */
    public function activityRevertHandler(string $handlerClass): void
    {
        app()->tag([$handlerClass], ActivityRevertHandler::TAG);
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
    }

    /**
     * @param  class-string<HasSchema>  $schemaClass
     */
    public function settingsSchema(string $group, string $schemaClass, ?string $key = null): void
    {
        resolve(SettingsSchemaRegistry::class)->register($group, $schemaClass, $key);
    }

    /**
     * @param  class-string<SettingsContract>  $settingsClass
     */
    public function settingsClass(string $group, string $settingsClass): void
    {
        resolve(SettingsSchemaRegistry::class)->registerSettingsClass($group, $settingsClass);
    }

    public function settingsMetadata(SettingsGroupMetadata $metadata): void
    {
        resolve(SettingsSchemaRegistry::class)->registerMetadata($metadata);
    }
}
