<?php

declare(strict_types=1);

namespace Capell\Admin\Providers;

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Admin\Actions\Users\RecordActAsOwnerActivityAction;
use Capell\Admin\Console\Commands\CacheConfiguratorsCommand;
use Capell\Admin\Console\Commands\CacheWidgetsCommand;
use Capell\Admin\Console\Commands\ClearCacheCommand;
use Capell\Admin\Console\Commands\ClearConfiguratorsCacheCommand;
use Capell\Admin\Console\Commands\ClearWidgetsCacheCommand;
use Capell\Admin\Console\Commands\InstallCommand;
use Capell\Admin\Console\Commands\PublishResourcesCommand;
use Capell\Admin\Console\Commands\RepairComposerDriftCommand;
use Capell\Admin\Console\Commands\SendUpgradeSummaryNotificationCommand;
use Capell\Admin\Console\Commands\SetupCommand;
use Capell\Admin\Console\Commands\SyncPermissionsCommand;
use Capell\Admin\Console\Commands\UpgradeCommand;
use Capell\Admin\Console\Commands\ValidateThemesCommand;
use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Contracts\Bridges\UserResourceBridge;
use Capell\Admin\Contracts\Dashboard\ContentHealthDataProvider;
use Capell\Admin\Contracts\Dashboard\MyWorkQueueDataProvider;
use Capell\Admin\Contracts\Dashboard\RecentlyPublishedDataProvider;
use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Contracts\DashboardReports\ActivityTrailQueryProvider;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Contracts\Diagnostics\SiteHealthWidget;
use Capell\Admin\Contracts\Extenders\PageEditExtender;
use Capell\Admin\Contracts\Extenders\PageExportExtender;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Contracts\Extenders\PublishPanelExtender;
use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Contracts\Support\FlagIconRenderer as FlagIconRendererContract;
use Capell\Admin\Data\AdminAssetData;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Data\ImportEntryData;
use Capell\Admin\Data\Reports\ReportDefinitionData;
use Capell\Admin\Enums\AdminAssetEnum;
use Capell\Admin\Enums\AdminNotificationGroupEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Events\ServingAdmin;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Imports\RedirectImporter;
use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Admin\Filament\Pages\Reports\AccessibilityReadinessReport;
use Capell\Admin\Filament\Pages\Reports\DemoInstallHealthReport;
use Capell\Admin\Filament\Pages\Reports\PackageReadinessReport;
use Capell\Admin\Filament\Pages\Reports\PublicRenderSafetyReport;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Admin\Filament\Resources\Redirects\Pages\ManageRedirects;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Settings\AdminSettingsSchema;
use Capell\Admin\Filament\Settings\Contributors\AdminDashboardSettingsContributor;
use Capell\Admin\Filament\Settings\CoreSettingsSchema;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Filament\Settings\ThemeStudioSettingsSchema;
use Capell\Admin\Filament\Widgets\CardsFilamentWidget;
use Capell\Admin\Filament\Widgets\ContentFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellAccountFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellInfoFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionDependencyGraphFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionDiagnosticsFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionHealthFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionRuntimeCompatibilityFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionUpdateReadinessFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\InstalledExtensionsFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\RecentlyChangedExtensionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioAdvancedFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioLaunchReadinessFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioQuickActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioTimelineFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioWorkQueueFilamentWidget;
use Capell\Admin\Livewire\Header\AdminTools;
use Capell\Admin\Livewire\Header\NavigationTree;
use Capell\Admin\Livewire\InfoBanner;
use Capell\Admin\Macros\Filament\ActionMacros;
use Capell\Admin\Macros\Filament\ColorPickerMacro;
use Capell\Admin\Macros\Filament\ColumnMacros;
use Capell\Admin\Macros\Filament\ComponentMacro;
use Capell\Admin\Macros\Filament\FieldMacro;
use Capell\Admin\Macros\Filament\HelperCountTextMacro;
use Capell\Admin\Macros\Filament\RepeaterMacro;
use Capell\Admin\Macros\Filament\SchemaMacro;
use Capell\Admin\Macros\Filament\SelectMacro;
use Capell\Admin\Macros\Filament\TestableMacro;
use Capell\Admin\Macros\Filament\TextInputMacro;
use Capell\Admin\Observers\LayoutObserver as AdminLayoutObserver;
use Capell\Admin\Observers\PageObserver as AdminPageObserver;
use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Policies\MediaPolicy;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Policies\UserPolicy;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\Activity\EventSourcedActivityRevertHandler;
use Capell\Admin\Support\AdminEventRegistry;
use Capell\Admin\Support\AdminEventRouter;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\AdminResourceResolver;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Bridges\AdminNotificationPreferencesUserResourceBridge;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Dashboard\DefaultSiteStatsDataProvider;
use Capell\Admin\Support\Dashboard\NullContentHealthDataProvider;
use Capell\Admin\Support\Dashboard\NullMyWorkQueueDataProvider;
use Capell\Admin\Support\Dashboard\NullRecentlyPublishedDataProvider;
use Capell\Admin\Support\DashboardReports\NullActivityTrailQueryProvider;
use Capell\Admin\Support\Diagnostics\ExtensionHealthSiteHealthWidget;
use Capell\Admin\Support\Diagnostics\RegistryInspector;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Admin\Support\Extensions\ExtensionOperationsRequestCache;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Admin\Support\Icons\FlagIconRenderer;
use Capell\Admin\Support\ImportEntryRegistry;
use Capell\Admin\Support\Install\AdminPermissionSynchronizer;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\DefaultPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\HomePageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\MaintenancePageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\NotFoundPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\SystemPageBlueprintInterceptor;
use Capell\Admin\Support\Makers\AdminBladeComponentMaker;
use Capell\Admin\Support\Makers\AdminConfiguratorMaker;
use Capell\Admin\Support\Makers\FilamentWidgetMaker;
use Capell\Admin\Support\Media\AdminSpatieMediaFieldFactory;
use Capell\Admin\Support\Navigation\AdminNavigationBadgeCountCache;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Capell\Admin\Support\Pages\DefaultPageTableStatusResolver;
use Capell\Admin\Support\Publish\WorkflowPublishPanelExtender;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Admin\Support\Subscribers\AdminConfiguratorsSubscriber;
use Capell\Admin\Support\Themes\ThemeLibraryRuntime;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Contracts\AdminPermissionSynchronizer as AdminPermissionSynchronizerContract;
use Capell\Core\Contracts\AdminResourceResolver as AdminResourceResolverContract;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Redirects\PageUrlRedirectUrlRecorder;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use CmsMulti\FilamentClearCache\Facades\FilamentClearCache;
use Composer\InstalledVersions;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\Livewire\Partials\DataStoreOverride;
use Filament\Tables\Columns\Column;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Override;
use Spatie\LaravelPackageTools\Package;
use STS\FilamentImpersonate\Events\EnterImpersonation;
use STS\FilamentImpersonate\Events\LeaveImpersonation;

class AdminServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-admin';

    public static string $packageName = 'capell-app/admin';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasViews('capell-admin')
            ->hasCommands([
                CacheWidgetsCommand::class,
                CacheConfiguratorsCommand::class,
                ClearWidgetsCacheCommand::class,
                ClearCacheCommand::class,
                ClearConfiguratorsCacheCommand::class,
                InstallCommand::class,
                PublishResourcesCommand::class,
                RepairComposerDriftCommand::class,
                SendUpgradeSummaryNotificationCommand::class,
                SetupCommand::class,
                SyncPermissionsCommand::class,
                UpgradeCommand::class,
                ValidateThemesCommand::class,
            ])
            ->hasConfigFile(['capell-admin'])
            ->hasTranslations()
            ->hasRoute('web');

        $this->optimizes(
            optimize: CacheConfiguratorsCommand::class,
            clear: ClearConfiguratorsCacheCommand::class,
            key: 'capell-admin-configurators',
        );

        $this->optimizes(
            optimize: CacheWidgetsCommand::class,
            clear: ClearWidgetsCacheCommand::class,
            key: 'capell-admin-widgets',
        );
    }

    #[Override]
    public function registeringPackage(): void
    {
        parent::registeringPackage();

        // Admin overrides the core default factory with a decorator that adds
        // translated label + max upload size. Plugins (e.g. capell/media-library)
        // can rebind MediaFieldFactory later to swap the backend entirely.
        $this->app->bind(MediaFieldFactory::class, AdminSpatieMediaFieldFactory::class);
        $this->app->bind(
            'capell.admin.create-default-pages-action',
            fn (): callable => function (Site $site, ?Collection $languages = null, ?array $pages = null): void {
                CreateDefaultPagesAction::run(site: $site, languages: $languages, pages: $pages);
            },
        );
        $this->app->singletonIf(FlagIconRendererContract::class, FlagIconRenderer::class);
        $this->app->singletonIf(PageTableStatusResolver::class, DefaultPageTableStatusResolver::class);

        $this->app->singleton(ExtensionPageRegistry::class, fn (): ExtensionPageRegistry => new ExtensionPageRegistry);
        $this->app->singleton(AdminNotificationGroupRegistry::class, fn (): AdminNotificationGroupRegistry => new AdminNotificationGroupRegistry);
        $this->app->singleton(WidgetDiscovery::class);
        $this->app->singleton(ActivityResourceLinkRegistry::class);
        $this->app->singleton(CapellAdminManager::class, fn (): CapellAdminManager => new CapellAdminManager);
        $this->app->singleton(AdminResourceResolverContract::class, AdminResourceResolver::class);
        $this->app->singleton(AdminPermissionSynchronizerContract::class, AdminPermissionSynchronizer::class);
        $this->app->singleton(AdminBridgeRegistrar::class, fn (): AdminBridgeRegistrar => new AdminBridgeRegistrar);
        $this->app->singleton(AdminBridgeRegistry::class, fn (): AdminBridgeRegistry => CapellAdmin::getAdminBridgeRegistry());
        $this->app->singleton(AdminSchemaExtensionPipeline::class);
        $this->app->singleton(ImportEntryRegistry::class, fn (): ImportEntryRegistry => new ImportEntryRegistry);

        $this->app->tag([], PageTableExtender::TAG);
        $this->app->tag([], PageEditExtender::TAG);
        $this->app->tag([], PageExportExtender::TAG);

        $this->app->tag([AdminDashboardSettingsContributor::class], DashboardSettingsContributor::TAG);
        $this->app->tag([ExtensionHealthSiteHealthWidget::class], SiteHealthWidget::TAG);

        $this->app->singletonIf(ContentHealthDataProvider::class, NullContentHealthDataProvider::class);
        $this->app->singletonIf(RecentlyPublishedDataProvider::class, NullRecentlyPublishedDataProvider::class);
        $this->app->singletonIf(MyWorkQueueDataProvider::class, NullMyWorkQueueDataProvider::class);
        $this->app->singletonIf(SiteStatsDataProvider::class, DefaultSiteStatsDataProvider::class);
        $this->app->singletonIf(ActivityTrailQueryProvider::class, NullActivityTrailQueryProvider::class);
        $this->app->scoped(AdminDashboardDataRequestCache::class);
        // Filament swaps Livewire's data store so partial rendering can share component state.
        // Keep that store stable for the request so Livewire validation state persists.
        $this->app->singleton(DataStore::class, DataStoreOverride::class);
        $this->app->singletonIf(PageExporter::class, NullPageExporter::class);
        $this->app->singletonIf(RedirectUrlRecorder::class, PageUrlRedirectUrlRecorder::class);
        $this->app->singleton(RegistryInspectorInterface::class, RegistryInspector::class);
        $this->app->singleton(ExtensionManagementSurfaceRegistry::class, fn (): ExtensionManagementSurfaceRegistry => new ExtensionManagementSurfaceRegistry);
        $this->app->scoped(ExtensionOperationsRequestCache::class);
        $this->app->singleton(ExtensionsPageActionRegistry::class, fn (): ExtensionsPageActionRegistry => new ExtensionsPageActionRegistry);
        $this->app->scoped(AdminNavigationBadgeCountCache::class);
        $this->app->scoped(ThemeLibraryRuntime::class);
        $this->reserveAdminFrontendPath();
        $this->reserveAdminFrontendDomain();

        $this->app->afterResolving(MakerRegistryInterface::class, function (MakerRegistryInterface $registry): void {
            $registry->register($this->app->make(AdminBladeComponentMaker::class));
            $registry->register($this->app->make(AdminConfiguratorMaker::class));
            $registry->register($this->app->make(FilamentWidgetMaker::class));
        });

        $this->app->afterResolving(ImportEntryRegistry::class, function (ImportEntryRegistry $registry): void {
            $registry->register(new ImportEntryData(
                key: 'redirects.csv',
                labelKey: 'capell-admin::exchanger.import.redirects',
                descriptionKey: 'capell-admin::exchanger.import.redirects_description',
                icon: 'heroicon-o-arrow-up-tray',
                sort: 30,
                pageClasses: [ManageRedirects::class],
                actionFactory: fn (): ImportAction => ImportAction::make('importRedirects')
                    ->label(__('capell-admin::exchanger.import.redirects'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->authorize(fn (): bool => Gate::allows('import', RedirectResource::getModel()))
                    ->importer(RedirectImporter::class),
                authorize: fn (): bool => Gate::allows('import', RedirectResource::getModel()),
            ));
        });

        $this
            ->registerPackageMetadata()
            ->registerMacros()
            ->registerAssets()
            ->registerNotificationGroups()
            ->registerPages()
            ->registerCoreReports()
            ->registerResources()
            ->registerWidgets()
            ->registerDashboardFilamentWidgets()
            ->registerOverviewStats();
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        FilamentClearCache::addCommand('capell:admin-clear-cache');

        return $this
            ->registerAboutInfo()
            ->bootAdminBridges()
            ->registerWidgetComponents()
            ->registerBlazeComponents()
            ->registerServingEvents()
            ->registerLivewireComponents()
            ->registerPublishCommands()
            ->registerSubscribers()
            ->registerModelInterceptors()
            ->registerAdminEventSystem()
            ->registerActAsOwnerAuditing()
            ->registerEventSourcingBridges()
            ->registerPolicies()
            ->registerSettingsSchemas()
            ->registerUpgradeNotificationSchedule()
            ->registerContentRetentionSchedule()
            ->registerModelObservers();
    }

    private function reserveAdminFrontendPath(): void
    {
        $adminPath = AdminPanelEntrypoint::path();

        if ($adminPath === '') {
            return;
        }

        $reservedFrontendPathRegistry = implode('\\', [
            'Capell',
            'Frontend',
            'Support',
            'Routing',
            'ReservedFrontendPathRegistry',
        ]);

        if (! class_exists($reservedFrontendPathRegistry)) {
            return;
        }

        if ($this->app->bound($reservedFrontendPathRegistry)) {
            $this->reserveFrontendPathPrefix($this->app->make($reservedFrontendPathRegistry), $adminPath);
        }

        $this->app->afterResolving(
            $reservedFrontendPathRegistry,
            function (object $registry) use ($adminPath): void {
                $this->reserveFrontendPathPrefix($registry, $adminPath);
            },
        );
    }

    private function reserveFrontendPathPrefix(object $registry, string $prefix): void
    {
        $callback = [$registry, 'reservePrefix'];

        if (is_callable($callback)) {
            $callback($prefix);
        }
    }

    private function reserveAdminFrontendDomain(): void
    {
        $adminDomain = AdminPanelEntrypoint::domain();

        if ($adminDomain === null) {
            return;
        }

        $reservedFrontendDomainRegistry = implode('\\', [
            'Capell',
            'Frontend',
            'Support',
            'Routing',
            'ReservedFrontendDomainRegistry',
        ]);

        if (! class_exists($reservedFrontendDomainRegistry)) {
            return;
        }

        if ($this->app->bound($reservedFrontendDomainRegistry)) {
            $this->reserveFrontendDomain($this->app->make($reservedFrontendDomainRegistry), $adminDomain);
        }

        $this->app->afterResolving(
            $reservedFrontendDomainRegistry,
            function (object $registry) use ($adminDomain): void {
                $this->reserveFrontendDomain($registry, $adminDomain);
            },
        );
    }

    private function reserveFrontendDomain(object $registry, string $domain): void
    {
        $callback = [$registry, 'reserve'];

        if (is_callable($callback)) {
            $callback($domain);
        }
    }

    private function registerResources(): self
    {
        foreach (ResourceEnum::cases() as $resourceEnum) {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
                class: $resourceEnum->value,
                group: $resourceEnum->name,
            ));
        }

        return $this;
    }

    private function registerNotificationGroups(): self
    {
        $this->app->afterResolving(AdminNotificationGroupRegistry::class, function (AdminNotificationGroupRegistry $registry): void {
            $registry->register(
                key: AdminNotificationGroupEnum::PackageOperations,
                label: AdminNotificationGroupEnum::PackageOperations->label(),
                description: AdminNotificationGroupEnum::PackageOperations->description(),
                defaultRecipients: fn (): Collection => $this->defaultPackageOperationRecipients(),
            );
        });

        $this->app->tag([AdminNotificationPreferencesUserResourceBridge::class], UserResourceBridge::TAG);

        return $this;
    }

    /** @return Collection<int, Model> */
    private function defaultPackageOperationRecipients(): Collection
    {
        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! is_a($userModel, Model::class, true)) {
            return new Collection;
        }

        return $userModel::query()
            ->get()
            ->filter(function (Model $user): bool {
                if (method_exists($user, 'isGlobalAdmin') && $user->isGlobalAdmin()) {
                    return true;
                }

                return method_exists($user, 'hasRole')
                    && $user->hasRole(config('capell.roles.super_admin', 'super_admin'));
            })
            ->values();
    }

    private function registerPages(): self
    {
        foreach (PageEnum::cases() as $pageEnum) {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page($pageEnum->value));
        }

        return $this;
    }

    private function registerCoreReports(): self
    {
        foreach ($this->coreReports() as $report) {
            CapellAdmin::registerReport($report);
        }

        return $this;
    }

    /** @return list<ReportDefinitionData> */
    private function coreReports(): array
    {
        return [
            new ReportDefinitionData(
                key: AccessibilityReadinessReport::REPORT_KEY,
                label: 'capell-admin::reports.accessibility_readiness_label',
                description: 'capell-admin::reports.accessibility_readiness_description',
                package: static::$packageName,
                category: 'capell-admin::reports.category_content',
                pageClass: AccessibilityReadinessReport::class,
                navigationSort: 35,
                capabilityTags: ['accessibility', 'languages', 'media'],
            ),
            new ReportDefinitionData(
                key: PublishingReadinessReport::REPORT_KEY,
                label: 'capell-admin::reports.publishing_readiness_label',
                description: 'capell-admin::reports.publishing_readiness_description',
                package: static::$packageName,
                category: 'capell-admin::reports.category_workflow',
                pageClass: PublishingReadinessReport::class,
                navigationSort: 60,
                capabilityTags: ['publishing', 'workflow'],
            ),
            new ReportDefinitionData(
                key: DemoInstallHealthReport::REPORT_KEY,
                label: 'capell-admin::reports.demo_install_health_label',
                description: 'capell-admin::reports.demo_install_health_description',
                package: static::$packageName,
                category: 'capell-admin::reports.category_operations',
                pageClass: DemoInstallHealthReport::class,
                navigationSort: 100,
                capabilityTags: ['demo', 'install'],
            ),
            new ReportDefinitionData(
                key: PackageReadinessReport::REPORT_KEY,
                label: 'capell-admin::reports.package_readiness_label',
                description: 'capell-admin::reports.package_readiness_description',
                package: static::$packageName,
                category: 'capell-admin::reports.category_operations',
                pageClass: PackageReadinessReport::class,
                navigationSort: 110,
                capabilityTags: ['packages', 'readiness'],
            ),
            new ReportDefinitionData(
                key: PublicRenderSafetyReport::REPORT_KEY,
                label: 'capell-admin::reports.public_render_safety_label',
                description: 'capell-admin::reports.public_render_safety_description',
                package: static::$packageName,
                category: 'capell-admin::reports.category_security',
                pageClass: PublicRenderSafetyReport::class,
                navigationSort: 120,
                capabilityTags: ['frontend', 'security'],
            ),
        ];
    }

    private function registerWidgets(): self
    {
        foreach (FilamentWidgetEnum::cases() as $widgetEnum) {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::widget($widgetEnum->value));
        }

        return $this;
    }

    private function registerAssets(): self
    {
        foreach (AdminAssetEnum::cases() as $assetEnum) {
            CapellAdmin::registerAsset(
                $assetEnum->getAsset(),
                new AdminAssetData(
                    formClass: $assetEnum->getFormClass(),
                    createAction: $assetEnum->getCreateActionClass(),
                    defaultDataAction: $assetEnum->getDefaultDataActionClass(),
                ),
            );
        }

        return $this;
    }

    private function registerPublishCommands(): self
    {
        $this->publishes([
            $this->package->basePath('/../publishes/config/') => config_path(),
        ], 'capell-admin-config');

        return $this;
    }

    private function registerLivewireComponents(): self
    {
        if (! $this->app->bound('livewire.finder')) {
            return $this;
        }

        Livewire::component('capell-admin::header.admin-tools', AdminTools::class);
        Livewire::component('capell-admin::header.navigation-tree', NavigationTree::class);
        Livewire::component('capell-admin::info-banner', InfoBanner::class);
        // Plain alias (not the `capell-admin::` Livewire namespace, which resolves
        // to Capell\Admin\Livewire and would shadow this class living under
        // Capell\Admin\Filament\Livewire).
        Livewire::component('capell-admin-publish-status-panel', PublishStatusPanel::class);

        if ($this->isLivewireV3() === false) {
            Livewire::addNamespace(
                namespace: 'capell-admin',
                classNamespace: 'Capell\\Admin\\Livewire',
                viewPath: __DIR__ . '/../../resources/views/livewire',
                classPath: __DIR__ . '/../Livewire',
                classViewPath: __DIR__ . '/../../resources/views/livewire',
            );
        }

        return $this;
    }

    private function registerAboutInfo(): self
    {
        if ($this->app->runningInConsole() && (class_exists(AboutCommand::class) && class_exists(InstalledVersions::class))) {
            AboutCommand::add('Capell', [
                self::$name => fn (): ?string => CapellCore::getInstalledPrettyVersion(static::$packageName),
            ]);
        }

        return $this;
    }

    private function registerServingEvents(): self
    {
        if (! $this->app->bound('filament')) {
            return $this;
        }

        Filament::serving(function (): void {
            Livewire::forceAssetInjection();

            event(new ServingAdmin);
        });

        return $this;
    }

    private function registerActAsOwnerAuditing(): self
    {
        Event::listen(
            EnterImpersonation::class,
            static function (EnterImpersonation $event): void {
                RecordActAsOwnerActivityAction::run(
                    supportUser: $event->impersonator,
                    ownerUser: $event->impersonated,
                    event: RecordActAsOwnerActivityAction::EVENT_STARTED,
                );
            },
        );

        Event::listen(
            LeaveImpersonation::class,
            static function (LeaveImpersonation $event): void {
                RecordActAsOwnerActivityAction::run(
                    supportUser: $event->impersonator,
                    ownerUser: $event->impersonated,
                    event: RecordActAsOwnerActivityAction::EVENT_STOPPED,
                );
            },
        );

        return $this;
    }

    /**
     * Wire the event-sourcing admin bridges: route page activity-revert through
     * the event-sourcing rollback, and register the workflow publish-panel
     * extender. The engine and aggregates live in core; admin owns the UI seam.
     */
    private function registerEventSourcingBridges(): self
    {
        resolve(AdminBridgeRegistrar::class)->activityRevertHandler(EventSourcedActivityRevertHandler::class);

        $this->app->tag([WorkflowPublishPanelExtender::class], PublishPanelExtender::TAG);

        return $this;
    }

    private function bootAdminBridges(): self
    {
        CapellAdmin::bootAdminBridges(static::$packageName);

        return $this;
    }

    private function registerDashboardFilamentWidgets(): self
    {
        CapellAdmin::registerDashboardFilamentWidget(CapellAccountFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(CapellInfoFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(ListPagesFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(RecentActivityFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(MarketingStudioQuickActionsFilamentWidget::class, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(MarketingStudioWorkQueueFilamentWidget::class, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(MarketingStudioLaunchReadinessFilamentWidget::class, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(MarketingStudioTimelineFilamentWidget::class, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(MarketingStudioAdvancedFilamentWidget::class, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionStatsOverviewFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionHealthFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionDiagnosticsFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionUpdateReadinessFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionDependencyGraphFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionRuntimeCompatibilityFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(ExtensionActionsFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(RecentlyChangedExtensionsFilamentWidget::class, DashboardEnum::Extensions);
        CapellAdmin::registerDashboardFilamentWidget(InstalledExtensionsFilamentWidget::class, DashboardEnum::Extensions);

        return $this;
    }

    private function registerOverviewStats(): self
    {
        CapellAdmin::registerOverviewStat(
            key: 'capell_overview.pages',
            label: fn (): string => __('capell-admin::dashboard.stat_total_pages'),
            value: fn (): int => Page::query()->count(),
            group: fn (): string => __('capell-admin::dashboard.overview_group_core'),
            description: fn (): string => __('capell-admin::dashboard.overview_stat_pages_description'),
            sort: 10,
            defaultEnabled: true,
            settingsKey: 'page_status',
            settingsLabel: fn (): string => __('capell-admin::dashboard.widget_capell_overview'),
            settingsDescription: fn (): string => __('capell-admin::dashboard.widget_page_status_description'),
        );

        CapellAdmin::registerOverviewStat(
            key: 'capell_overview.sites',
            label: fn (): string => __('capell-admin::dashboard.stat_sites'),
            value: fn (): int => Site::query()->count(),
            group: fn (): string => __('capell-admin::dashboard.overview_group_core'),
            description: fn (): string => __('capell-admin::dashboard.overview_stat_sites_description'),
            sort: 20,
            defaultEnabled: true,
            settingsKey: 'page_status',
            settingsLabel: fn (): string => __('capell-admin::dashboard.widget_capell_overview'),
            settingsDescription: fn (): string => __('capell-admin::dashboard.widget_page_status_description'),
        );

        CapellAdmin::registerOverviewStat(
            key: 'capell_overview.languages',
            label: fn (): string => __('capell-admin::dashboard.stat_languages'),
            value: fn (): int => Language::query()->count(),
            group: fn (): string => __('capell-admin::dashboard.overview_group_core'),
            description: fn (): string => __('capell-admin::dashboard.overview_stat_languages_description'),
            sort: 30,
            defaultEnabled: true,
            settingsKey: 'page_status',
            settingsLabel: fn (): string => __('capell-admin::dashboard.widget_capell_overview'),
            settingsDescription: fn (): string => __('capell-admin::dashboard.widget_page_status_description'),
        );

        CapellAdmin::registerOverviewStat(
            key: 'capell_overview.page_types',
            label: fn (): string => __('capell-admin::dashboard.stat_page_types'),
            value: fn (): int => Blueprint::query()->pageType()->count(),
            group: fn (): string => __('capell-admin::dashboard.overview_group_core'),
            description: fn (): string => __('capell-admin::dashboard.overview_stat_page_types_description'),
            sort: 40,
            defaultEnabled: true,
            settingsKey: 'page_status',
            settingsLabel: fn (): string => __('capell-admin::dashboard.widget_capell_overview'),
            settingsDescription: fn (): string => __('capell-admin::dashboard.widget_page_status_description'),
        );

        return $this;
    }

    private function registerPolicies(): self
    {
        // Policies live in Capell\Admin\Policies but the models they gate
        // live in Capell\Core\Models. Laravel's convention-based resolver
        // looks for App\Policies\* from Capell\Core\Models\* and won't find
        // them. Register explicitly so that Gate::inspect/allows/denies
        // invoked outside Filament's resource-scoped policy lookup (e.g.
        // from Actions, CLI, queued jobs) still resolves the right policy.
        Gate::policy(Page::class, PagePolicy::class);
        Gate::policy(PageUrl::class, RedirectPolicy::class);
        Gate::policy(Layout::class, LayoutPolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);

        $userModel = config('auth.providers.users.model');

        if (is_string($userModel) && is_a($userModel, Model::class, true)) {
            Gate::policy($userModel, UserPolicy::class);
        }

        return $this;
    }

    private function registerAdminEventSystem(): self
    {
        $this->app->singleton(AdminEventRegistry::class, static fn (): AdminEventRegistry => new AdminEventRegistry);

        $this->app->singleton(
            AdminEventRouter::class,
            fn (ContainerContract $app): AdminEventRouter => new AdminEventRouter($app, $app->make(AdminEventRegistry::class)),
        );

        return $this;
    }

    private function registerSubscribers(): self
    {
        CapellCore::subscriberManager()->subscribe(AdminConfiguratorsSubscriber::class);

        return $this;
    }

    private function registerWidgetComponents(): self
    {
        CapellAdmin::registerDiscoverableWidgets(app_path('Filament/Widgets'), 'App\\Filament\\Widgets');
        CapellAdmin::registerWidget(CardsFilamentWidget::class);
        CapellAdmin::registerWidget(ContentFilamentWidget::class);

        return $this;
    }

    private function registerBlazeComponents(): self
    {
        RegisterBlazeOptimizedViewsAction::run(__DIR__ . '/../../resources/views/components/alert.blade.php');

        return $this;
    }

    private function registerPackageMetadata(): self
    {
        CapellCore::registerPackage(
            static::$packageName,
            type: static::getType(),
            serviceProviderClass: static::class,
            path: dirname(__DIR__, 2),
            version: CapellCore::getInstalledPrettyVersion(static::$packageName),
            setupCommand: 'capell:admin-setup',
            setupParams: [
                'url',
                'user',
                'languages',
                'sites',
                'assets',
                'theme',
                'skip-panel-integration',
                'panel',
                'configurators',
                'no-colors',
                'no-widgets',
                'no-navigation',
                'skip-permission-sync',
                'force',
            ],
        );

        return $this;
    }

    private function registerMacros(): self
    {
        Action::mixin(new ActionMacros);
        Column::mixin(new ColumnMacros);
        Component::mixin(new ComponentMacro);
        ColorPicker::mixin(new ColorPickerMacro);
        Field::mixin(new FieldMacro);
        Repeater::mixin(new RepeaterMacro);
        Schema::mixin(new SchemaMacro);
        Select::mixin(new SelectMacro);
        Testable::mixin(new TestableMacro);
        Textarea::mixin(new HelperCountTextMacro);
        TextInput::mixin(new TextInputMacro);
        TextInput::mixin(new HelperCountTextMacro);

        Builder::macro(
            'whereNullOr',
            function (string $column, string $operator, mixed $value = null) {
                /** @var Builder $this */
                return $this->where(
                    fn (Builder $query) => $query->whereNull($column)->orWhere($column, $operator, $value),
                );
            },
        );

        return $this;
    }

    private function registerModelInterceptors(): self
    {
        /** @var class-string<Blueprint> $blueprintModel */
        $blueprintModel = Blueprint::class;

        CapellCore::registerModelInterceptor(
            $blueprintModel,
            interceptorClass: DefaultPageBlueprintInterceptor::class,
            key: [
                'key' => PageTypeEnum::Default->value,
                'type' => BlueprintSubjectEnum::Page,
            ],
        );

        CapellCore::registerModelInterceptor(
            $blueprintModel,
            interceptorClass: NotFoundPageBlueprintInterceptor::class,
            key: [
                'key' => PageTypeEnum::NotFound->value,
                'type' => BlueprintSubjectEnum::Page,
            ],
        );

        CapellCore::registerModelInterceptor(
            $blueprintModel,
            interceptorClass: HomePageBlueprintInterceptor::class,
            key: [
                'key' => PageTypeEnum::Home->value,
                'type' => BlueprintSubjectEnum::Page,
            ],
        );

        CapellCore::registerModelInterceptor(
            $blueprintModel,
            interceptorClass: MaintenancePageBlueprintInterceptor::class,
            key: [
                'key' => PageTypeEnum::Maintenance->value,
                'type' => BlueprintSubjectEnum::Page,
            ],
        );

        CapellCore::registerModelInterceptor(
            $blueprintModel,
            interceptorClass: SystemPageBlueprintInterceptor::class,
            key: [
                'key' => PageTypeEnum::System->value,
                'type' => BlueprintSubjectEnum::Page,
            ],
        );

        return $this;
    }

    private function registerSettingsSchemas(): self
    {
        $registry = resolve(SettingsSchemaRegistry::class);

        $registry->registerSettingsClass('core', CoreSettings::class);
        $registry->registerMetadata(new SettingsGroupMetadata(
            group: 'core',
            label: 'capell-admin::generic.core',
            icon: Heroicon::OutlinedCog6Tooth,
            navigationGroup: 'capell-admin::navigation.group_system',
            navigationSort: 90,
            packageName: CapellServiceProvider::$packageName,
        ));
        $registry->register('core', CoreSettingsSchema::class);

        $registry->registerSettingsClass('admin', AdminSettings::class);
        $registry->registerMetadata(new SettingsGroupMetadata(
            group: 'admin',
            label: 'capell-admin::generic.admin_settings',
            icon: Heroicon::OutlinedWrenchScrewdriver,
            navigationGroup: 'capell-admin::navigation.group_system',
            navigationSort: 91,
            packageName: static::$packageName,
        ));
        $registry->register('admin', AdminSettingsSchema::class);
        $registry->register('admin', DashboardSettingsSchema::class);

        $registry->registerSettingsClass('theme_studio', ThemeStudioSettings::class);
        $registry->registerMetadata(new SettingsGroupMetadata(
            group: 'theme_studio',
            label: 'capell-admin::generic.theme_studio',
            icon: Heroicon::OutlinedSwatch,
            navigationGroup: 'capell-admin::navigation.group_system',
            navigationSort: 92,
            packageName: CapellServiceProvider::$packageName,
        ));
        $registry->register('theme_studio', ThemeStudioSettingsSchema::class);

        return $this;
    }

    private function registerUpgradeNotificationSchedule(): self
    {
        if (! $this->configBoolean('capell-admin.upgrades.notifications.enabled', true)) {
            return $this;
        }

        $frequency = config('capell-admin.upgrades.notifications.frequency', 'weekly');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($frequency): void {
            $event = $schedule
                ->command('capell:admin-upgrade-summary-email')
                ->withoutOverlapping()
                ->onOneServer();

            match ($frequency) {
                'monthly' => $event->monthly(),
                default => $event->weekly(),
            };
        });

        return $this;
    }

    /**
     * Schedule the nightly retention sweeps for soft-deleted media and stale
     * page content snapshots — both operate on core-owned tables, so the admin
     * package owns their cadence rather than each consuming app.
     */
    private function registerContentRetentionSchedule(): self
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('capell:purge-soft-deleted-media')
                ->dailyAt('03:00')
                ->withoutOverlapping()
                ->onOneServer();
        });

        return $this;
    }

    private function configBoolean(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function registerModelObservers(): self
    {
        Page::observe(AdminPageObserver::class);
        Layout::observe(AdminLayoutObserver::class);

        return $this;
    }
}
