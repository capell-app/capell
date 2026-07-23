<?php

declare(strict_types=1);

namespace Capell\Core\Providers;

use BackedEnum;
use Capell\Core\Actions\BladeComponentFacadeResolver;
use Capell\Core\Actions\ConfigureMailMarkdownComponentsAction;
use Capell\Core\Actions\ConfigureMailMarkdownLogoAction;
use Capell\Core\Console\Commands\BackupHealthCommand;
use Capell\Core\Console\Commands\CacheComponentsCommand;
use Capell\Core\Console\Commands\ClearComponentsCacheCommand;
use Capell\Core\Console\Commands\CloudBootstrapCommand;
use Capell\Core\Console\Commands\CoreFakerCommand;
use Capell\Core\Console\Commands\CreateBackupCommand;
use Capell\Core\Console\Commands\DeleteMigrationsCommand;
use Capell\Core\Console\Commands\DoctorCommand;
use Capell\Core\Console\Commands\ExtensionAuditCommand;
use Capell\Core\Console\Commands\ExtensionPlaygroundCommand;
use Capell\Core\Console\Commands\FakerCommand;
use Capell\Core\Console\Commands\ImportSiteSpecCommand;
use Capell\Core\Console\Commands\InstallCommand;
use Capell\Core\Console\Commands\InstallExtensionCommand;
use Capell\Core\Console\Commands\MakeActionCommand;
use Capell\Core\Console\Commands\MakeBlueprintCommand;
use Capell\Core\Console\Commands\MakeCommand;
use Capell\Core\Console\Commands\MakeDataCommand;
use Capell\Core\Console\Commands\MakeExtenderCommand;
use Capell\Core\Console\Commands\MakeExtensionCommand;
use Capell\Core\Console\Commands\MakeSchemaCommand;
use Capell\Core\Console\Commands\MakeThemeCommand;
use Capell\Core\Console\Commands\PackageCacheCommand;
use Capell\Core\Console\Commands\PackageClearCacheCommand;
use Capell\Core\Console\Commands\PackageLintCommand;
use Capell\Core\Console\Commands\PruneBackupsCommand;
use Capell\Core\Console\Commands\PublishComponentsCommand;
use Capell\Core\Console\Commands\PublishMigrationsCommand;
use Capell\Core\Console\Commands\PurgeSoftDeletedMediaCommand;
use Capell\Core\Console\Commands\RestoreBackupCommand;
use Capell\Core\Console\Commands\RollbackCommand;
use Capell\Core\Console\Commands\RollupMetricEventsCommand;
use Capell\Core\Console\Commands\RuntimeRefreshCommand;
use Capell\Core\Console\Commands\ThemeDoctorCommand;
use Capell\Core\Console\Commands\UninstallExtensionCommand;
use Capell\Core\Console\Commands\UpgradeCommand;
use Capell\Core\Contracts\BladeComponentResolverInterface;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Contracts\ProjectBuild\ProjectBuildArtifactHandler;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Contracts\RedirectResolver;
use Capell\Core\Data\AssetData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ComponentTypeEnum;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Events\PageSaved;
use Capell\Core\Events\ServingCapell;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Listeners\CreateRedirectsForChangedPageUrls;
use Capell\Core\Listeners\PageTranslationCreatingListener;
use Capell\Core\Listeners\PageTranslationDeletedListener;
use Capell\Core\Listeners\PageTranslationSavedListener;
use Capell\Core\Macros\BlueprintMacros;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Models\MetricEvent;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRoleRestriction;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Octane\Resettable;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Assets\VendorAssetConditionRegistry;
use Capell\Core\Support\Backup\DatabaseBackupDriverRegistry;
use Capell\Core\Support\Backup\Drivers\MySqlDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\PostgresDatabaseBackupDriver;
use Capell\Core\Support\Backup\Drivers\SqliteDatabaseBackupDriver;
use Capell\Core\Support\Bootstrap\EventSourcingBootstrapper;
use Capell\Core\Support\Bootstrap\PackageRegistryBootstrapper;
use Capell\Core\Support\Bootstrap\SettingsBootstrapper;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Components\ComponentRegistry;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\ContentGraph\Extractors\LayoutContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\MediaContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\PageContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\PageUrlContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\SiteContentGraphExtractor;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Support\Links\LinkableContentRegistry;
use Capell\Core\Support\Links\PageLinkableContentProvider;
use Capell\Core\Support\Makers\BuiltIn\ActionMaker;
use Capell\Core\Support\Makers\BuiltIn\AssetBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\BlueprintMaker;
use Capell\Core\Support\Makers\BuiltIn\CoreSchemaMaker;
use Capell\Core\Support\Makers\BuiltIn\DataMaker;
use Capell\Core\Support\Makers\BuiltIn\ExtenderMaker;
use Capell\Core\Support\Makers\BuiltIn\PageBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\PageLivewireComponentMaker;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Support\Makers\MakerSafety;
use Capell\Core\Support\Media\BackendResolver;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\Support\Media\SpatieMediaFieldFactory;
use Capell\Core\Support\Metrics\DenyMetricScopeAuthorizer;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\Metrics\MetricEventRegistry;
use Capell\Core\Support\Metrics\MetricsManager;
use Capell\Core\Support\Migration\MigrationFilesystem;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\Models\ModelInterceptorRegistry;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Capell\Core\Support\ProjectBuild\ProjectBuildArtifactHandlerRegistry;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestMigrationRegistry;
use Capell\Core\Support\ProjectBuild\SiteSpecProjectBuildArtifactHandler;
use Capell\Core\Support\Publishing\GatePublicationTransitionAuthorizer;
use Capell\Core\Support\Redirects\PageUrlRedirectHitRecorder;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\SiteAccess\SiteAccessPolicyRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\Subscriber\SubscriberManager;
use Capell\Core\Support\Subscriber\SubscriberRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Http\Middleware\ResolveThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Core\ThemeStudio\Theme\PagePresentationRegistry;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Core\ThemeStudio\Theme\WidgetPresentationRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Octane\Contracts\OperationTerminated;
use Override;
use Spatie\LaravelPackageTools\Package;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

class CapellServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell';

    public static string $packageName = 'capell-app/capell';

    private bool $themePreviewMiddlewareRegistered = false;

    #[Override]
    public function registeringPackage(): void
    {
        $this->app->singleton(CapellCoreManager::class);
        $this->app->singleton(ComponentRegistry::class);
        $this->app->alias(CapellCoreManager::class, 'capell-admin');
        $this->app->scoped(RuntimeSchemaState::class);
        $this->app->scoped(ProjectBuildArtifactHandlerRegistry::class);
        $this->app->scoped(ProjectBuildManifestMigrationRegistry::class);
        $this->app->tag([SiteSpecProjectBuildArtifactHandler::class], ProjectBuildArtifactHandler::TAG);
        $this->app->scoped(SiteSpecApplierRegistry::class);

        $this->app->register(MediaLibraryServiceProvider::class);
        config(['media-library.media_model' => Media::class]);
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', self::$name);
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'capell-core');
        $this->app->make(PackageRegistryBootstrapper::class)->bootstrap();

        parent::registeringPackage();
    }

    public function bootingPackage(): void
    {
        $this
            ->registerPublishCommands()
            ->registerAboutInfo('capell-app/core')
            ->registerMorphMap()
            ->registerGatePolicyGuesser()
            ->registerTranslationEvents()
            ->bootEventSourcing();
    }

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name)
            ->hasConfigFile(['backup', 'capell', 'redirects'])
            ->hasTranslations();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $package->hasCommands([
            CacheComponentsCommand::class,
            BackupHealthCommand::class,
            CloudBootstrapCommand::class,
            ClearComponentsCacheCommand::class,
            CoreFakerCommand::class,
            CreateBackupCommand::class,
            DeleteMigrationsCommand::class,
            DoctorCommand::class,
            ExtensionAuditCommand::class,
            ExtensionPlaygroundCommand::class,
            FakerCommand::class,
            ImportSiteSpecCommand::class,
            InstallExtensionCommand::class,
            UninstallExtensionCommand::class,
            InstallCommand::class,
            MakeCommand::class,
            MakeActionCommand::class,
            MakeDataCommand::class,
            MakeExtenderCommand::class,
            MakeExtensionCommand::class,
            MakeSchemaCommand::class,
            MakeThemeCommand::class,
            PackageLintCommand::class,
            MakeBlueprintCommand::class,
            UpgradeCommand::class,
            RollbackCommand::class,
            RollupMetricEventsCommand::class,
            RestoreBackupCommand::class,
            RuntimeRefreshCommand::class,
            ThemeDoctorCommand::class,
            PurgeSoftDeletedMediaCommand::class,
            PublishComponentsCommand::class,
            PublishMigrationsCommand::class,
            PruneBackupsCommand::class,
            PackageCacheCommand::class,
            PackageClearCacheCommand::class,
        ]);
    }

    public function packageRegistered(): void
    {
        $this
            ->registerSettingsSchemaRegistry()
            ->registerPublicationTransitions()
            ->registerMailMarkdownComponents()
            ->registerLocalAppThemeDiscovery()
            ->registerPackageMetadata(setting: CoreSettings::class)
            ->registerMacros()
            ->registerOctaneStateReset()
            ->registerModels()
            ->registerProtectedTables()
            ->registerMetricSchedule()
            ->bindManagers()
            ->registerLinkableContentProviders()
            ->registerConfigSettings()
            ->registerComponentTypes()
            ->registerCoreRenderables()
            ->registerTypes()
            ->registerAssets()
            ->registerDiscoverableComponents()
            ->registerMakers()
            ->registerContentGraphExtractors()
            ->registerThemeRuntime()
            ->registerOptimization()
            ->registerLockdownStore()
            ->registerRedirectBehavior()
            ->registerEventSourcing()
            ->configureMailMarkdownLogo()
            ->dispatchServingEvent();
    }

    private function registerPublicationTransitions(): self
    {
        $this->app->bind(
            AuthorizesPublicationTransition::class,
            GatePublicationTransitionAuthorizer::class,
        );

        return $this;
    }

    /**
     * Boot-phase event-sourcing wiring: the recording bridge that records a
     * revision after every page save. Projector/reactor registration happens in
     * the register phase (see registerEventSourcing) so it is in place before
     * Spatie builds the Projectionist during its own boot.
     */
    private function bootEventSourcing(): self
    {
        $this->app->make(EventSourcingBootstrapper::class)->boot();

        return $this;
    }

    /**
     * Register the reusable event-sourcing foundation. The engine itself
     * (stored events, projectors, reactors) is owned by core; admin and other
     * packages opt their models in via the EventSourcedRegistry. See the
     * sanctioned Package Independence exception documented in CONTRIBUTING.md.
     */
    private function registerEventSourcing(): self
    {
        $this->app->make(EventSourcingBootstrapper::class)->register();

        return $this;
    }

    private function registerMailMarkdownComponents(): self
    {
        ConfigureMailMarkdownComponentsAction::run();

        return $this;
    }

    private function registerOctaneStateReset(): self
    {
        $this->app->singleton(FlushResettableState::class);

        if (! interface_exists(OperationTerminated::class)) {
            return $this;
        }

        $this->app->make(Dispatcher::class)->listen(
            OperationTerminated::class,
            function (object $event): void {
                $sandbox = method_exists($event, 'sandbox') ? $event->sandbox() : null;
                $application = $sandbox instanceof Application ? $sandbox : $this->app;

                $application->make(FlushResettableState::class)->handle($application);
            },
        );

        return $this;
    }

    private function registerLocalAppThemeDiscovery(): self
    {
        $this->app->singleton(LocalAppThemeDefinitionRepository::class);

        return $this;
    }

    private function registerMorphMap(): self
    {
        $morphMap = collect(CapellCore::getModels())
            ->mapWithKeys(fn (string $modelClass, string $name): array => [Str::snake($name) => $modelClass])
            ->all();

        if (! array_key_exists('User', Relation::morphMap())) {
            $morphMap['User'] = config('auth.providers.users.model');
        }

        Relation::morphMap($morphMap);
        Relation::requireMorphMap();

        return $this;
    }

    private function registerGatePolicyGuesser(): self
    {
        Gate::guessPolicyNamesUsing(
            fn (string $modelClass): string => 'App\\Policies\\' . class_basename($modelClass) . 'Policy',
        );

        return $this;
    }

    private function registerMacros(): self
    {
        SchemaBlueprint::mixin(new BlueprintMacros);

        return $this;
    }

    private function bindManagers(): self
    {
        $this->app->bind(
            BladeComponentResolverInterface::class,
            BladeComponentFacadeResolver::class,
        );

        $this->app->singleton(BackendResolver::class);
        $this->app->bindIf(MediaFieldFactory::class, SpatieMediaFieldFactory::class);

        $this->app->singleton(CapellCacheManager::class);
        $this->app->singleton(ModelInterceptorRegistry::class);
        $this->app->singletonIf(CapellPackageRegistry::class);

        $this->app->tag([CapellCoreManager::class, ComponentRegistry::class], Resettable::TAG);
        $this->app->scoped(ImageUrlPolicy::class);
        $this->app->singleton(PackageSurfaceRegistrar::class, fn ($app): PackageSurfaceRegistrar => new PackageSurfaceRegistrar(
            $app->make(CapellCoreManager::class),
            $app->make(SettingsSchemaRegistry::class),
            $app->make(MetricCollectorRegistry::class),
        ));
        $this->app->singleton(SubscriberRegistry::class);
        $this->app->alias(SubscriberRegistry::class, SubscriberManager::class);
        $this->app->singleton(RenderableRegistry::class);
        $this->app->singleton(LinkableContentRegistry::class);
        $this->app->singleton(ContentGraphRegistry::class, fn (): ContentGraphRegistry => new ContentGraphRegistry($this->app));
        $this->app->singleton(ThemeChromeRegistry::class);
        $this->app->singleton(ThemeInstallDefaultsRegistry::class);
        $this->app->singleton(InstallPatchRegistry::class);
        $this->app->singleton(PresentationPresetRegistry::class);
        $this->app->singleton(VendorAssetConditionRegistry::class);
        $this->app->singleton(SiteAccessPolicyRegistry::class);
        $this->app->singleton(DatabaseBackupDriverRegistry::class, fn ($app): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry([
            $app->make(SqliteDatabaseBackupDriver::class),
            $app->make(MySqlDatabaseBackupDriver::class),
            $app->make(PostgresDatabaseBackupDriver::class),
        ]));
        $this->app->singleton(MakerRegistryInterface::class, MakerRegistry::class);
        $this->app->singleton(MakerSafety::class, fn (): MakerSafety => new MakerSafety);
        $this->app->singleton(PluginPackagesFetcher::class);
        $this->app->singleton(MigrationFilesystemInterface::class, MigrationFilesystem::class);
        $this->app->singleton(ProcessFactoryInterface::class, SymfonyProcessFactory::class);
        $this->app->singleton(MetricEventRegistry::class);
        $this->app->singleton(MetricCollectorRegistry::class);
        $this->app->singleton(MetricsManager::class);
        $this->app->bindIf(MetricScopeAuthorizer::class, DenyMetricScopeAuthorizer::class);

        return $this;
    }

    private function registerLinkableContentProviders(): self
    {
        /** @var LinkableContentRegistry $registry */
        $registry = $this->app->make(LinkableContentRegistry::class);

        $registry->register(new PageLinkableContentProvider);

        return $this;
    }

    private function registerMakers(): self
    {
        $this->callAfterResolving(MakerRegistryInterface::class, function (MakerRegistryInterface $registry): void {
            foreach ([
                ActionMaker::class,
                DataMaker::class,
                ExtenderMaker::class,
                CoreSchemaMaker::class,
                BlueprintMaker::class,
                PageBladeComponentMaker::class,
                PageLivewireComponentMaker::class,
                AssetBladeComponentMaker::class,
            ] as $makerClass) {
                $registry->register($this->app->make($makerClass));
            }
        });

        return $this;
    }

    private function registerContentGraphExtractors(): self
    {
        $this->app->tag([
            LayoutContentGraphExtractor::class,
            MediaContentGraphExtractor::class,
            PageContentGraphExtractor::class,
            PageUrlContentGraphExtractor::class,
            SiteContentGraphExtractor::class,
        ], ContentGraphRegistry::TAG);

        return $this;
    }

    private function registerModels(): self
    {
        CapellCore::registerModels([
            AssetAttachment::class,
            Language::class,
            Layout::class,
            Media::class,
            Page::class,
            PageRoleRestriction::class,
            PageUrl::class,
            Site::class,
            SiteDomain::class,
            Theme::class,
            Translation::class,
            Blueprint::class,
            UpgradeLogEntry::class,
        ]);

        CapellCore::registerPageVariation(
            new PageVariationData(
                name: 'page',
                model: Page::class,
            ),
        );

        return $this;
    }

    private function registerProtectedTables(): self
    {
        CapellCore::registerProtectedTable(
            static fn (): string => (new MetricCollectionRun)->getTable(),
        );
        CapellCore::registerProtectedTable(
            static fn (): string => (new MetricDailyRollup)->getTable(),
        );
        CapellCore::registerProtectedTable(
            static fn (): string => (new MetricEvent)->getTable(),
        );

        return $this;
    }

    private function registerMetricSchedule(): self
    {
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('capell:metrics:rollup')
                ->dailyAt('00:20')
                ->timezone('UTC')
                ->withoutOverlapping()
                ->onOneServer();
        });

        return $this;
    }

    private function registerComponentTypes(): self
    {
        foreach (ComponentTypeEnum::cases() as $componentType) {
            /** @var class-string<BackedEnum> $enumClass */
            $enumClass = $componentType->value;
            CapellCore::registerComponents($componentType->name, $enumClass::cases());
        }

        return $this;
    }

    private function registerCoreRenderables(): self
    {
        $registry = $this->app->make(RenderableRegistry::class);

        $registry->register(new RenderableDefinitionData(
            key: LivewirePageComponentEnum::Default->value,
            type: RenderableTypeEnum::Page,
            livewire: LivewirePageComponentEnum::Default->value,
        ));

        foreach (AssetComponentEnum::cases() as $assetComponent) {
            $registry->register(new RenderableDefinitionData(
                key: $assetComponent->value,
                type: RenderableTypeEnum::Asset,
                blade: $assetComponent->bladeView(),
            ));
        }

        return $this;
    }

    private function registerTypes(): self
    {
        foreach (BlueprintSubjectEnum::cases() as $type) {
            $model = $type->getModel();

            // Labels must be registered as plain strings (not closures) because
            // PageTypeData is stored in CapellCoreManager and may be serialised
            // by Livewire. Closures dehydrate as `{}` and crash Livewire with
            // "Property type not supported". When you need a BlueprintSubjectEnum value
            // inside a Livewire component, use BlueprintSubjectDescriptorData::fromEnum()
            // which resolves labels eagerly before crossing the Livewire boundary.
            CapellCore::registerPageType(
                new PageTypeData(
                    name: $type->value,
                    model: $model,
                    label: $type->getLabel(),
                ),
            );
        }

        return $this;
    }

    private function registerAssets(): self
    {
        foreach (AssetEnum::cases() as $asset) {
            CapellCore::registerAsset(
                new AssetData(
                    name: $asset->name,
                    model: $asset->getModel(),
                    label: fn (): string => $asset->getLabel(),
                    icon: fn (): string|BackedEnum => $asset->getIcon(),
                    hasTranslations: $asset->hasTranslations(),
                ),
            );
        }

        return $this;
    }

    private function registerDiscoverableComponents(): self
    {
        CapellCore::registerDiscoverableComponents(in: resource_path('views/components/capell'), for: 'capell');

        return $this;
    }

    private function dispatchServingEvent(): self
    {
        event(new ServingCapell);

        return $this;
    }

    private function registerPublishCommands(): self
    {
        $this->publishes([
            $this->package->basePath('/../publishes/config/') => config_path(),
        ], 'capell-core-config');

        return $this;
    }

    private function registerConfigSettings(): self
    {
        $this->app->make(SettingsBootstrapper::class)->bootstrap();

        return $this;
    }

    private function registerThemeRuntime(): self
    {
        $this->app->singleton(ThemeRegistry::class);
        $this->app->singleton(PagePresentationRegistry::class);
        $this->app->singleton(WidgetPresentationRegistry::class);
        $this->app->singleton(ThemeTokenStore::class);
        $this->app->scoped(ThemeRuntimeSettings::class, ThemeStudioSettings::class);
        $this->app->singleton(
            ThemePreviewSigner::class,
            fn (): ThemePreviewSigner => new ThemePreviewSigner(config('app.key', 'capell-theme')),
        );
        $this->app->scoped(ThemePreviewContext::class, fn (): ThemePreviewContext => ThemePreviewContext::none());
        $this->app->singleton(InstallProfileRepository::class);

        $this->surface()->settingsClass(ThemeStudioSettings::group(), ThemeStudioSettings::class);

        $this->callAfterResolving(Router::class, function (Router $router): void {
            $this->registerThemePreviewMiddleware($router);
        });

        return $this;
    }

    private function registerThemePreviewMiddleware(Router $router): void
    {
        if ($this->themePreviewMiddlewareRegistered) {
            return;
        }

        $router->pushMiddlewareToGroup('web', ResolveThemePreviewContext::class);
        $this->themePreviewMiddlewareRegistered = true;
    }

    private function registerOptimization(): self
    {
        $this->optimizes(
            optimize: PackageCacheCommand::class,
            clear: PackageClearCacheCommand::class,
            key: 'capell-package-manifests',
        );

        $this->optimizes(
            optimize: CacheComponentsCommand::class,
            clear: CacheComponentsCommand::class,
            key: 'capell-components-cache',
        );

        return $this;
    }

    private function registerLockdownStore(): self
    {
        $this->app->singleton(LockdownStore::class);
        $this->app->tag([LockdownStore::class], Resettable::TAG);
        $this->app->singleton(LockdownStaticCacheSwitcher::class);

        return $this;
    }

    private function registerRedirectBehavior(): self
    {
        $this->app->singleton(PageUrlRedirectHitRecorder::class);

        $redirectResolverContract = RedirectResolver::class;

        $this->app->singleton(
            $redirectResolverContract,
            PageUrlRedirectResolver::class,
        );

        Event::listen(PageSaved::class, [CreateRedirectsForChangedPageUrls::class, 'handle']);

        return $this;
    }

    private function configureMailMarkdownLogo(): self
    {
        $this->app->booted(function (): void {
            ConfigureMailMarkdownLogoAction::run();
        });

        return $this;
    }

    private function registerSettingsSchemaRegistry(): self
    {
        $this->app->singleton(
            SettingsSchemaRegistry::class,
            fn (): SettingsSchemaRegistry => new SettingsSchemaRegistry,
        );

        return $this;
    }

    private function registerTranslationEvents(): self
    {
        Event::listen('eloquent.creating: ' . Translation::class, PageTranslationCreatingListener::class);
        Event::listen('eloquent.saved: ' . Translation::class, PageTranslationSavedListener::class);
        Event::listen('eloquent.deleted: ' . Translation::class, PageTranslationDeletedListener::class);

        return $this;
    }
}
