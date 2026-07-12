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
use Capell\Core\Console\Commands\PruneBackupsCommand;
use Capell\Core\Console\Commands\PublishComponentsCommand;
use Capell\Core\Console\Commands\PublishMigrationsCommand;
use Capell\Core\Console\Commands\PurgeSoftDeletedMediaCommand;
use Capell\Core\Console\Commands\RestoreBackupCommand;
use Capell\Core\Console\Commands\RollbackCommand;
use Capell\Core\Console\Commands\ThemeDoctorCommand;
use Capell\Core\Console\Commands\UninstallExtensionCommand;
use Capell\Core\Console\Commands\UpgradeCommand;
use Capell\Core\Contracts\BladeComponentResolverInterface;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Contracts\Media\MediaFieldFactory;
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
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Listeners\RecordPageRevision;
use Capell\Core\EventSourcing\Projectors\PageProjector;
use Capell\Core\EventSourcing\Reactors\PageWorkflowReactor;
use Capell\Core\EventSourcing\Rollback\RollbackValidatorRegistry;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;
use Capell\Core\EventSourcing\Rollback\Validators\PageReferentialIntegrityRollbackValidator;
use Capell\Core\EventSourcing\Rollback\Validators\PageUrlUniquenessRollbackValidator;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
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
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\ContentGraph\Extractors\LayoutContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\MediaContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\PageContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\PageUrlContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\SiteContentGraphExtractor;
use Capell\Core\Support\Database\RuntimeSchemaState;
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
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Media\BackendResolver;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\Support\Media\SpatieMediaFieldFactory;
use Capell\Core\Support\Migration\MigrationFilesystem;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Capell\Core\Support\Presentation\PresentationPresetRegistry;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Capell\Core\Support\Redirects\PageUrlRedirectRecorder;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Support\Security\LockdownStaticCacheSwitcher;
use Capell\Core\Support\Security\LockdownStore;
use Capell\Core\Support\Settings\SettingsSchemaBootstrapper;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\Support\Subscriber\SubscriberManager;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Http\Middleware\ResolveThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Core\ThemeStudio\Theme\BookingEntryPointRegistry;
use Capell\Core\ThemeStudio\Theme\PagePresentationRegistry;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Core\ThemeStudio\Theme\WidgetPresentationRegistry;
use Composer\InstalledVersions;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Octane\Contracts\OperationTerminated;
use ReflectionClass;
use Spatie\EventSourcing\EventSourcingServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Throwable;

class CapellServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell';

    public static string $packageName = 'capell-app/capell';

    private bool $themePreviewMiddlewareRegistered = false;

    public function registeringPackage(): void
    {
        $this->app->scoped(RuntimeSchemaState::class);

        $this->app->register(MediaLibraryServiceProvider::class);
        config(['media-library.media_model' => Media::class]);
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', self::$name);
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'capell-core');
        $this->bootCapellPackageRegistry();
    }

    public function bootingPackage(): void
    {
        $this
            ->registerPublishCommands()
            ->enforceMorphMapRequirement()
            ->registerAboutInfo()
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
            MakeBlueprintCommand::class,
            UpgradeCommand::class,
            RollbackCommand::class,
            RestoreBackupCommand::class,
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
            ->registerMailMarkdownComponents()
            ->registerLocalAppThemeDiscovery()
            ->registerPackageMetadata()
            ->registerDiscoveredPackageMetadata()
            ->registerMacros()
            ->registerOctaneStateReset()
            ->registerModels()
            ->bindManagers()
            ->registerLinkableContentProviders()
            ->registerConfigSettings()
            ->registerComponentTypes()
            ->registerCoreRenderables()
            ->registerTypes()
            ->registerAssets()
            ->registerUserMorphMap()
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

    /**
     * Boot-phase event-sourcing wiring: the recording bridge that records a
     * revision after every page save. Projector/reactor registration happens in
     * the register phase (see registerEventSourcing) so it is in place before
     * Spatie builds the Projectionist during its own boot.
     */
    private function bootEventSourcing(): self
    {
        Event::listen(PageSaved::class, [RecordPageRevision::class, 'handle']);

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
        $this->app->singleton(EventSourcedRegistry::class);
        $this->app->singleton(RollbackValidatorRegistry::class);
        $this->app->singleton(StateDiffer::class);

        // Guarantee Spatie's full event-sourcing config defaults are present
        // (stored_event_repository, serializers, …) regardless of provider boot
        // order or whether the host merged the package config itself.
        $eventSourcingProviderFile = new ReflectionClass(EventSourcingServiceProvider::class)->getFileName();

        if ($eventSourcingProviderFile !== false) {
            $this->mergeConfigFrom(
                dirname($eventSourcingProviderFile, 2) . '/config/event-sourcing.php',
                'event-sourcing',
            );
        }

        // Register the projector/reactor via config (read lazily when Spatie
        // builds the Projectionist) rather than the facade, so wiring is
        // independent of provider boot order. Auto-discovery is disabled: Capell
        // registers its handlers explicitly and never scans an app path.
        config([
            'event-sourcing.projectors' => array_values(array_unique([
                ...(array) config('event-sourcing.projectors', []),
                PageProjector::class,
            ])),
            'event-sourcing.reactors' => array_values(array_unique([
                ...(array) config('event-sourcing.reactors', []),
                PageWorkflowReactor::class,
            ])),
            'event-sourcing.auto_discover_projectors_and_reactors' => [],
            // Order replay/rollback reads by aggregate_version, not the default
            // autoincrement id: per-aggregate reconstitution then uses the
            // (aggregate_uuid, aggregate_version) index instead of filesorting
            // wide jsonb payload rows.
            'event-sourcing.aggregate_event_order_column' => 'aggregate_version',
        ]);

        $this->app->make(EventSourcedRegistry::class)->register(
            Page::class,
            PageAggregate::class,
            PageStateSerializer::class,
        );

        $this->app->make(RollbackValidatorRegistry::class)->register(
            Page::class,
            PageUrlUniquenessRollbackValidator::class,
        );

        $this->app->make(RollbackValidatorRegistry::class)->register(
            Page::class,
            PageReferentialIntegrityRollbackValidator::class,
        );

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

    private function bootCapellPackageRegistry(): void
    {
        $registry = new CapellPackageRegistry;
        $cachePath = $this->app->bootstrapPath('cache/capell-package-manifests.php');

        $loader = new ManifestLoader(new ManifestValidator);

        $manifests = file_exists($cachePath) ? $this->hydrateCachedPackageManifests($cachePath, $loader) : $loader->discover();

        $registry->fill($manifests);
        $this->app->instance(CapellPackageRegistry::class, $registry);

        $packageLoader = new CapellPackageLoader($this->app, $registry);
        $packageLoader->loadProviders();
    }

    /**
     * @return array<string, CapellManifestData>
     */
    private function hydrateCachedPackageManifests(string $cachePath, ManifestLoader $loader): array
    {
        try {
            $cached = require $cachePath;

            throw_unless(is_array($cached), InvalidManifestException::class, 'Cached Capell package manifest must return an array.');

            return array_map(
                CapellManifestData::fromArray(...),
                $cached,
            );
        } catch (Throwable) {
            @unlink($cachePath);

            return $loader->discover();
        }
    }

    private function registerAboutInfo(): self
    {
        if ($this->app->runningInConsole() && (class_exists(AboutCommand::class) && class_exists(InstalledVersions::class))) {
            AboutCommand::add('Capell', [
                self::$name => fn (): ?string => CapellCore::getInstalledPrettyVersion('capell-app/core'),
            ]);
        }

        return $this;
    }

    private function enforceMorphMapRequirement(): self
    {
        if ((string) $this->app->environment() !== '') {
            Relation::requireMorphMap();
        }

        return $this;
    }

    private function registerMorphMap(): self
    {
        Relation::morphMap(
            collect(CapellCore::getModels())
                ->mapWithKeys(fn (string $modelClass, string $name): array => [Str::snake($name) => $modelClass])
                ->all(),
        );

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
        $this->app->scoped('capell-admin', fn (): CapellCoreManager => new CapellCoreManager);

        $this->app->bind(
            BladeComponentResolverInterface::class,
            BladeComponentFacadeResolver::class,
        );

        $this->app->singleton(BackendResolver::class);
        $this->app->bindIf(MediaFieldFactory::class, SpatieMediaFieldFactory::class);

        $this->app->singleton(CapellCoreManager::class, fn (): CapellCoreManager => new CapellCoreManager);
        $this->app->tag([CapellCoreManager::class], Resettable::TAG);
        $this->app->scoped(ImageUrlPolicy::class);
        $this->app->tag([ImageUrlPolicy::class], Resettable::TAG);
        $this->app->singleton(PackageSurfaceRegistrar::class, fn ($app): PackageSurfaceRegistrar => new PackageSurfaceRegistrar(
            $app->make(CapellCoreManager::class),
            $app->make(SettingsSchemaRegistry::class),
        ));
        $this->app->singleton(SubscriberManager::class, fn (): SubscriberManager => new SubscriberManager);
        $this->app->singleton(RenderableRegistry::class, fn (): RenderableRegistry => new RenderableRegistry);
        $this->app->singleton(LinkableContentRegistry::class, fn (): LinkableContentRegistry => new LinkableContentRegistry);
        $this->app->singleton(ContentGraphRegistry::class, fn (): ContentGraphRegistry => new ContentGraphRegistry($this->app));
        $this->app->singleton(ThemeChromeRegistry::class, fn (): ThemeChromeRegistry => new ThemeChromeRegistry);
        $this->app->singleton(ThemeInstallDefaultsRegistry::class, fn (): ThemeInstallDefaultsRegistry => new ThemeInstallDefaultsRegistry);
        $this->app->singleton(PresentationPresetRegistry::class, fn (): PresentationPresetRegistry => new PresentationPresetRegistry);
        $this->app->singleton(VendorAssetConditionRegistry::class, fn (): VendorAssetConditionRegistry => new VendorAssetConditionRegistry);
        $this->app->singleton(DatabaseBackupDriverRegistry::class, fn ($app): DatabaseBackupDriverRegistry => new DatabaseBackupDriverRegistry([
            $app->make(SqliteDatabaseBackupDriver::class),
            $app->make(MySqlDatabaseBackupDriver::class),
            $app->make(PostgresDatabaseBackupDriver::class),
        ]));
        $this->app->singleton(MakerRegistryInterface::class, fn (): MakerRegistry => new MakerRegistry);
        $this->app->singleton(MakerSafety::class, fn (): MakerSafety => new MakerSafety);
        $this->app->singleton(PluginPackagesFetcher::class);
        $this->app->singleton(MigrationFilesystemInterface::class, MigrationFilesystem::class);
        $this->app->singleton(ProcessFactoryInterface::class, SymfonyProcessFactory::class);

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
        $this->app->afterResolving(MakerRegistryInterface::class, function (MakerRegistryInterface $registry): void {
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

    private function registerPackageMetadata(): self
    {
        CapellCore::registerPackage(
            self::$packageName,
            type: self::getType(),
            serviceProviderClass: self::class,
            path: dirname(__DIR__, 2),
            version: CapellCore::getInstalledPrettyVersion(self::$packageName),
            setting: CoreSettings::class,
        );

        return $this;
    }

    private function registerDiscoveredPackageMetadata(): self
    {
        $registry = $this->app->make(CapellPackageRegistry::class);

        foreach ($registry->all() as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }

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
                blade: match ($assetComponent) {
                    AssetComponentEnum::Card => 'capell::asset.index',
                    AssetComponentEnum::Media => 'capell::media.asset',
                    AssetComponentEnum::Page => 'capell::page.asset',
                    AssetComponentEnum::Tile => 'capell::asset.tile',
                },
            ));
        }

        return $this;
    }

    private function registerTypes(): self
    {
        foreach (BlueprintSubjectEnum::cases() as $type) {
            $model = $type->getModel();

            if (! is_subclass_of($model, Model::class)) {
                continue;
            }

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

    private function registerUserMorphMap(): self
    {
        $userModelKey = 'User';
        if (! array_key_exists($userModelKey, Relation::morphMap())) {
            Relation::morphMap([$userModelKey => config('auth.providers.users.model')]);
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
        $settingsProviderPath = new ReflectionClass(LaravelSettingsServiceProvider::class)->getFileName();

        if (is_string($settingsProviderPath)) {
            $settingsConfigPath = dirname($settingsProviderPath) . '/../config/settings.php';

            if (is_file($settingsConfigPath)) {
                config()->set('settings', array_merge(
                    require $settingsConfigPath,
                    config('settings', []),
                ));
            }
        }

        $settings = config('settings.settings', []);

        if (! in_array(CoreSettings::class, $settings, true)) {
            $settings[] = CoreSettings::class;
        }

        if (! in_array(ThemeStudioSettings::class, $settings, true)) {
            $settings[] = ThemeStudioSettings::class;
        }

        $packages = CapellCore::getPackages();

        foreach ($packages as $package) {
            if ($package->setting === null) {
                continue;
            }

            if ($package->setting === '') {
                continue;
            }

            if (in_array($package->setting, $settings, true)) {
                continue;
            }

            $settings[] = $package->setting;
        }

        config(['settings.settings' => $settings]);

        return $this;
    }

    private function registerThemeRuntime(): self
    {
        $this->app->singleton(ThemeRegistry::class);
        $this->app->singleton(PagePresentationRegistry::class);
        $this->app->singleton(WidgetPresentationRegistry::class);
        $this->app->singleton(BookingEntryPointRegistry::class);
        $this->app->singleton(ThemeTokenStore::class);
        $this->app->singleton(ThemeRuntimeSettings::class, ThemeStudioSettings::class);
        $this->app->singleton(
            ThemePreviewSigner::class,
            fn (): ThemePreviewSigner => new ThemePreviewSigner(config('app.key', 'capell-theme')),
        );
        $this->app->scoped(ThemePreviewContext::class, fn (): ThemePreviewContext => ThemePreviewContext::none());
        $this->app->singleton(InstallProfileRepository::class);

        $registerSettingsClass = function (SettingsSchemaRegistry $registry): void {
            $registry->registerSettingsClass(ThemeStudioSettings::group(), ThemeStudioSettings::class);
        };

        $this->app->afterResolving(SettingsSchemaRegistry::class, $registerSettingsClass);

        if ($this->app->resolved(SettingsSchemaRegistry::class)) {
            $registerSettingsClass($this->app->make(SettingsSchemaRegistry::class));
        }

        $this->app->afterResolving(Router::class, function (Router $router): void {
            $this->registerThemePreviewMiddleware($router);
        });

        if ($this->app->resolved(Router::class)) {
            $this->registerThemePreviewMiddleware($this->app->make(Router::class));
        }

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
        $this->app->singleton(PageUrlRedirectRecorder::class);

        $redirectResolverContract = RedirectResolver::class;

        if (interface_exists($redirectResolverContract)) {
            $this->app->singleton(
                $redirectResolverContract,
                PageUrlRedirectResolver::class,
            );
        }

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

        $this->app->singleton(
            SettingsSchemaBootstrapper::class,
            fn (): SettingsSchemaBootstrapper => new SettingsSchemaBootstrapper(
                resolve(SettingsSchemaRegistry::class),
            ),
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
