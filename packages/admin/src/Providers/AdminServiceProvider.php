<?php

declare(strict_types=1);

namespace Capell\Admin\Providers;

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Admin\Actions\Notifications\ResolveDefaultPackageOperationRecipientsAction;
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
use Capell\Admin\Macros\Database\BuilderMacros;
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
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Support\Bridges\AdminNotificationPreferencesUserResourceBridge;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Support\Dashboard\DefaultSiteStatsDataProvider;
use Capell\Admin\Support\Dashboard\NullContentHealthDataProvider;
use Capell\Admin\Support\Dashboard\NullMyWorkQueueDataProvider;
use Capell\Admin\Support\Dashboard\NullRecentlyPublishedDataProvider;
use Capell\Admin\Support\Dashboard\OverviewStatRegistry;
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
use Capell\Admin\Support\MarketingStudio\MarketingStudioActionRegistry;
use Capell\Admin\Support\Media\AdminSpatieMediaFieldFactory;
use Capell\Admin\Support\Navigation\AdminNavigationBadgeCountCache;
use Capell\Admin\Support\Notifications\AdminNotificationGroupRegistry;
use Capell\Admin\Support\Pages\DefaultPageTableStatusResolver;
use Capell\Admin\Support\Publish\WorkflowPublishPanelExtender;
use Capell\Admin\Support\Reports\ReportRegistry;
use Capell\Admin\Support\Schemas\AdminSchemaExtensionPipeline;
use Capell\Admin\Support\Subscribers\ActAsOwnerEventSubscriber;
use Capell\Admin\Support\Subscribers\AdminConfiguratorsSubscriber;
use Capell\Admin\Support\Themes\ThemeLibraryRuntime;
use Capell\Admin\Support\UserMenu\UserMenuItemRegistry;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
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
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use CmsMulti\FilamentClearCache\Facades\FilamentClearCache;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\DataStore;
use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;

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

        $this->app->singletonIf(ExtensionPageRegistry::class);
        $this->app->singletonIf(AdminNotificationGroupRegistry::class);
        $this->app->singleton(WidgetDiscovery::class);
        $this->app->singletonIf(ActivityResourceLinkRegistry::class);
        $this->app->singletonIf(AdminSurfaceContributionRegistry::class);
        $this->app->singletonIf(ReportRegistry::class);
        $this->app->singletonIf(DashboardFilamentWidgetRegistry::class);
        $this->app->singletonIf(MarketingStudioActionRegistry::class);
        $this->app->singletonIf(UserMenuItemRegistry::class);
        $this->app->singletonIf(OverviewStatRegistry::class);
        $this->app->singletonIf(AdminBridgeRegistry::class);
        $this->app->singletonIf(AdminBridgeRegistrar::class);

        $manager = CapellAdmin::getFacadeRoot();
        throw_unless($manager instanceof CapellAdminManager, RuntimeException::class, 'The Capell admin facade must resolve its manager.');

        $this->app->instance(CapellAdminManager::class, $manager);
        $this->app->instance(AdminSurfaceContributionRegistry::class, $manager->getAdminSurfaceRegistry());
        $this->app->instance(ReportRegistry::class, $manager->getReportRegistry());
        $this->app->instance(AdminBridgeRegistry::class, $manager->getAdminBridgeRegistry());
        $this->app->singleton(AdminResourceResolverContract::class, AdminResourceResolver::class);
        $this->app->singleton(AdminPermissionSynchronizerContract::class, AdminPermissionSynchronizer::class);
        $this->app->singleton(AdminSchemaExtensionPipeline::class);
        $this->app->singleton(ImportEntryRegistry::class);

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
        $this->app->singleton(ExtensionManagementSurfaceRegistry::class);
        $this->app->scoped(ExtensionOperationsRequestCache::class);
        $this->app->singleton(ExtensionsPageActionRegistry::class);
        $this->app->scoped(AdminNavigationBadgeCountCache::class);
        $this->app->scoped(ThemeLibraryRuntime::class);
        $this->reserveAdminFrontendPath();
        $this->reserveAdminFrontendDomain();

        $this->callAfterResolving(MakerRegistryInterface::class, function (MakerRegistryInterface $registry): void {
            $registry->register($this->app->make(AdminBladeComponentMaker::class));
            $registry->register($this->app->make(AdminConfiguratorMaker::class));
            $registry->register($this->app->make(FilamentWidgetMaker::class));
        });

        $this->callAfterResolving(ImportEntryRegistry::class, function (ImportEntryRegistry $registry): void {
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
            ->registerAdminPackageMetadata()
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
            ->registerBlazeOptimizedComponentViews()
            ->registerServingEvents()
            ->registerAdminLivewireComponents()
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

        $this->reserveAdminFrontendValue(
            'Capell\\Frontend\\Support\\Routing\\ReservedFrontendPathRegistry',
            'reservePrefix',
            $adminPath,
        );
    }

    private function reserveAdminFrontendDomain(): void
    {
        $adminDomain = AdminPanelEntrypoint::domain();

        if ($adminDomain === null) {
            return;
        }

        $this->reserveAdminFrontendValue(
            'Capell\\Frontend\\Support\\Routing\\ReservedFrontendDomainRegistry',
            'reserve',
            $adminDomain,
        );
    }

    /** @param class-string $registryClass */
    private function reserveAdminFrontendValue(string $registryClass, string $method, string $value): void
    {
        // Frontend is an optional package, so do not make its registry classes
        // a static Admin dependency or register callbacks for absent services.
        if (! class_exists($registryClass)) {
            return;
        }

        $this->callAfterResolving($registryClass, static function (object $registry) use ($method, $value): void {
            $registry->{$method}($value);
        });
    }

    private function registerResources(): self
    {
        return $this->registerAdminSurfaceCases(
            ResourceEnum::cases(),
            static fn (ResourceEnum $resourceEnum): AdminSurfaceContributionData => AdminSurfaceContributionData::resource(
                class: $resourceEnum->value,
                group: $resourceEnum->name,
            ),
        );
    }

    private function registerNotificationGroups(): self
    {
        $this->callAfterResolving(AdminNotificationGroupRegistry::class, static function (AdminNotificationGroupRegistry $registry): void {
            foreach (AdminNotificationGroupEnum::cases() as $group) {
                $registry->register(
                    key: $group,
                    label: $group->label(),
                    description: $group->description(),
                    defaultRecipients: ResolveDefaultPackageOperationRecipientsAction::run(...),
                );
            }
        });

        $this->app->tag([AdminNotificationPreferencesUserResourceBridge::class], UserResourceBridge::TAG);

        return $this;
    }

    private function registerPages(): self
    {
        return $this->registerAdminSurfaceCases(
            PageEnum::cases(),
            static fn (PageEnum $pageEnum): AdminSurfaceContributionData => AdminSurfaceContributionData::page($pageEnum->value),
        );
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
        $reports = [
            AccessibilityReadinessReport::class => [
                'label' => 'capell-admin::reports.accessibility_readiness_label',
                'description' => 'capell-admin::reports.accessibility_readiness_description',
                'category' => 'capell-admin::reports.category_content',
                'navigationSort' => 35,
                'capabilityTags' => ['accessibility', 'languages', 'media'],
            ],
            PublishingReadinessReport::class => [
                'label' => 'capell-admin::reports.publishing_readiness_label',
                'description' => 'capell-admin::reports.publishing_readiness_description',
                'category' => 'capell-admin::reports.category_workflow',
                'navigationSort' => 60,
                'capabilityTags' => ['publishing', 'workflow'],
            ],
            DemoInstallHealthReport::class => [
                'label' => 'capell-admin::reports.demo_install_health_label',
                'description' => 'capell-admin::reports.demo_install_health_description',
                'category' => 'capell-admin::reports.category_operations',
                'navigationSort' => 100,
                'capabilityTags' => ['demo', 'install'],
            ],
            PackageReadinessReport::class => [
                'label' => 'capell-admin::reports.package_readiness_label',
                'description' => 'capell-admin::reports.package_readiness_description',
                'category' => 'capell-admin::reports.category_operations',
                'navigationSort' => 110,
                'capabilityTags' => ['packages', 'readiness'],
            ],
            PublicRenderSafetyReport::class => [
                'label' => 'capell-admin::reports.public_render_safety_label',
                'description' => 'capell-admin::reports.public_render_safety_description',
                'category' => 'capell-admin::reports.category_security',
                'navigationSort' => 120,
                'capabilityTags' => ['frontend', 'security'],
            ],
        ];

        return array_map(
            fn (string $pageClass, array $definition): ReportDefinitionData => new ReportDefinitionData(
                key: $pageClass::REPORT_KEY,
                label: $definition['label'],
                description: $definition['description'],
                package: static::$packageName,
                category: $definition['category'],
                pageClass: $pageClass,
                navigationSort: $definition['navigationSort'],
                capabilityTags: $definition['capabilityTags'],
            ),
            array_keys($reports),
            array_values($reports),
        );
    }

    private function registerWidgets(): self
    {
        return $this->registerAdminSurfaceCases(
            FilamentWidgetEnum::cases(),
            static fn (FilamentWidgetEnum $widgetEnum): AdminSurfaceContributionData => AdminSurfaceContributionData::widget($widgetEnum->value),
        );
    }

    /**
     * @template TCase
     *
     * @param  list<TCase>  $cases
     * @param  callable(TCase): AdminSurfaceContributionData  $makeContribution
     */
    private function registerAdminSurfaceCases(array $cases, callable $makeContribution): self
    {
        foreach ($cases as $case) {
            CapellAdmin::contributeToAdminSurface($makeContribution($case));
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

    private function registerAdminLivewireComponents(): self
    {
        return $this->registerLivewireComponentDefinitions([
            'capell-admin::header.admin-tools' => AdminTools::class,
            'capell-admin::header.navigation-tree' => NavigationTree::class,
            'capell-admin::info-banner' => InfoBanner::class,
            // Plain alias because the namespace resolves to Capell\Admin\Livewire.
            'capell-admin-publish-status-panel' => PublishStatusPanel::class,
        ], [
            'namespace' => 'capell-admin',
            'classNamespace' => 'Capell\\Admin\\Livewire',
            'viewPath' => __DIR__ . '/../../resources/views/livewire',
            'classPath' => __DIR__ . '/../Livewire',
            'classViewPath' => __DIR__ . '/../../resources/views/livewire',
        ]);
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
        Event::subscribe(ActAsOwnerEventSubscriber::class);

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
        $widgets = [
            DashboardEnum::Main->value => [
                CapellAccountFilamentWidget::class,
                CapellInfoFilamentWidget::class,
                ListPagesFilamentWidget::class,
                RecentActivityFilamentWidget::class,
            ],
            DashboardEnum::MarketingStudio->value => [
                MarketingStudioQuickActionsFilamentWidget::class,
                MarketingStudioWorkQueueFilamentWidget::class,
                MarketingStudioLaunchReadinessFilamentWidget::class,
                MarketingStudioTimelineFilamentWidget::class,
                MarketingStudioAdvancedFilamentWidget::class,
            ],
            DashboardEnum::Extensions->value => [
                ExtensionStatsOverviewFilamentWidget::class,
                ExtensionHealthFilamentWidget::class,
                ExtensionDiagnosticsFilamentWidget::class,
                ExtensionUpdateReadinessFilamentWidget::class,
                ExtensionDependencyGraphFilamentWidget::class,
                ExtensionRuntimeCompatibilityFilamentWidget::class,
                ExtensionActionsFilamentWidget::class,
                RecentlyChangedExtensionsFilamentWidget::class,
                InstalledExtensionsFilamentWidget::class,
            ],
        ];

        foreach ($widgets as $dashboard => $widgetClasses) {
            foreach ($widgetClasses as $widgetClass) {
                CapellAdmin::registerDashboardFilamentWidget($widgetClass, DashboardEnum::from($dashboard));
            }
        }

        return $this;
    }

    private function registerOverviewStats(): self
    {
        $stats = [
            'pages' => ['label' => 'stat_total_pages', 'sort' => 10, 'value' => fn (): int => Page::query()->count()],
            'sites' => ['label' => 'stat_sites', 'sort' => 20, 'value' => fn (): int => Site::query()->count()],
            'languages' => ['label' => 'stat_languages', 'sort' => 30, 'value' => fn (): int => Language::query()->count()],
            'page_types' => ['label' => 'stat_page_types', 'sort' => 40, 'value' => fn (): int => Blueprint::query()->pageType()->count()],
        ];

        foreach ($stats as $key => $stat) {
            CapellAdmin::registerOverviewStat(
                key: 'capell_overview.' . $key,
                label: fn (): string => __(sprintf('capell-admin::dashboard.%s', $stat['label'])),
                value: $stat['value'],
                group: fn (): string => __('capell-admin::dashboard.overview_group_core'),
                description: fn (): string => __(sprintf('capell-admin::dashboard.overview_stat_%s_description', $key)),
                sort: $stat['sort'],
                defaultEnabled: true,
                settingsKey: 'page_status',
                settingsLabel: fn (): string => __('capell-admin::dashboard.widget_capell_overview'),
                settingsDescription: fn (): string => __('capell-admin::dashboard.widget_page_status_description'),
            );
        }

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
        $this->app->singleton(AdminEventRegistry::class);

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

    private function registerBlazeOptimizedComponentViews(): self
    {
        return $this->registerBlazeOptimizedViews([
            __DIR__ . '/../../resources/views/components/alert.blade.php',
        ]);
    }

    private function registerAdminPackageMetadata(): self
    {
        return parent::registerPackageMetadata(
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
        Builder::mixin(new BuilderMacros);

        return $this;
    }

    private function registerModelInterceptors(): self
    {
        /** @var class-string<Blueprint> $blueprintModel */
        $blueprintModel = Blueprint::class;

        $interceptors = [
            PageTypeEnum::Default->value => DefaultPageBlueprintInterceptor::class,
            PageTypeEnum::NotFound->value => NotFoundPageBlueprintInterceptor::class,
            PageTypeEnum::Home->value => HomePageBlueprintInterceptor::class,
            PageTypeEnum::Maintenance->value => MaintenancePageBlueprintInterceptor::class,
            PageTypeEnum::System->value => SystemPageBlueprintInterceptor::class,
        ];

        foreach ($interceptors as $pageType => $interceptorClass) {
            CapellCore::registerModelInterceptor(
                $blueprintModel,
                interceptorClass: $interceptorClass,
                key: [
                    'key' => $pageType,
                    'type' => BlueprintSubjectEnum::Page,
                ],
            );
        }

        return $this;
    }

    private function registerSettingsSchemas(): self
    {
        $surface = $this->surface();
        $settingsGroups = [
            'core' => [
                'class' => CoreSettings::class,
                'label' => 'capell-admin::generic.core',
                'icon' => Heroicon::OutlinedCog6Tooth,
                'sort' => 90,
                'package' => CapellServiceProvider::$packageName,
                'schemas' => [CoreSettingsSchema::class],
            ],
            'admin' => [
                'class' => AdminSettings::class,
                'label' => 'capell-admin::generic.admin_settings',
                'icon' => Heroicon::OutlinedWrenchScrewdriver,
                'sort' => 91,
                'package' => static::$packageName,
                'schemas' => [AdminSettingsSchema::class, DashboardSettingsSchema::class],
            ],
            'theme_studio' => [
                'class' => ThemeStudioSettings::class,
                'label' => 'capell-admin::generic.theme_studio',
                'icon' => Heroicon::OutlinedSwatch,
                'sort' => 92,
                'package' => CapellServiceProvider::$packageName,
                'schemas' => [ThemeStudioSettingsSchema::class],
            ],
        ];

        foreach ($settingsGroups as $group => $settings) {
            $surface->settingsClass($group, $settings['class']);
            $surface->settingsMetadata(new SettingsGroupMetadata(
                group: $group,
                label: $settings['label'],
                icon: $settings['icon'],
                navigationGroup: 'capell-admin::navigation.group_system',
                navigationSort: $settings['sort'],
                packageName: $settings['package'],
            ));

            foreach ($settings['schemas'] as $schema) {
                $surface->settingsSchema($group, $schema);
            }
        }

        return $this;
    }

    private function registerUpgradeNotificationSchedule(): self
    {
        if (! $this->configBoolean('capell-admin.upgrades.notifications.enabled', true)) {
            return $this;
        }

        $frequency = config('capell-admin.upgrades.notifications.frequency', 'weekly');

        $this->registerSchedule(function (Schedule $schedule) use ($frequency): void {
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
        $this->registerSchedule(function (Schedule $schedule): void {
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
