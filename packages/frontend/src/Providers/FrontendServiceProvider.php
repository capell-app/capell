<?php

declare(strict_types=1);

namespace Capell\Frontend\Providers;

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\AssetComponentEnum;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageDeleted as PageDeletedEvent;
use Capell\Core\Events\PageSaved as PageSavedEvent;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\Migration\MigrationFilesystem;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Actions\BuildFrontendResourceDebugOverlayPayloadAction;
use Capell\Frontend\Actions\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Frontend\Actions\GetLayoutContainerWidthAction;
use Capell\Frontend\Console\Commands\AfterInstallCommand;
use Capell\Frontend\Console\Commands\GenerateErrorPagesCommand;
use Capell\Frontend\Console\Commands\GenerateHtmlCommand;
use Capell\Frontend\Console\Commands\GenerateTailwindAssetsCommand;
use Capell\Frontend\Console\Commands\InstallCommand;
use Capell\Frontend\Console\Commands\UpgradeCommand;
use Capell\Frontend\Contracts\AdminAccessCheckerInterface;
use Capell\Frontend\Contracts\AssetsRegistryInterface;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\FontMimeTypeResolverInterface;
use Capell\Frontend\Contracts\Fragments\PublicFragmentReferenceCodec;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Contracts\FrontendComponentRegistryInterface;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Contracts\NullCacheBypassResolver;
use Capell\Frontend\Contracts\RedirectResolver;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Contracts\SystemPageResolver;
use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Capell\Frontend\Http\Middleware\ETagMiddleware;
use Capell\Frontend\Http\Middleware\NullWorkspaceContextMiddleware;
use Capell\Frontend\Http\Middleware\PreventAuthenticatedFrontendRenderingWhenHtmlCacheable;
use Capell\Frontend\Http\Middleware\RenderingStrategyMiddleware;
use Capell\Frontend\Http\Middleware\ResolveFrontendMiddleware;
use Capell\Frontend\Http\Middleware\ServeStaticMaintenancePage;
use Capell\Frontend\Http\View\RenderingStrategyViewComposer;
use Capell\Frontend\Listeners\OnFrontendContextResolved;
use Capell\Frontend\Listeners\PurgeCdnCacheOnPageChangeListener;
use Capell\Frontend\Livewire\Page\Page;
use Capell\Frontend\Observers\ErrorPageModelInvalidationObserver;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Settings\FrontendSettingsMigrationProvider;
use Capell\Frontend\Settings\FrontendSettingsReader;
use Capell\Frontend\Support\Assets\AssetOptimizationMiddleware;
use Capell\Frontend\Support\Assets\CoreFrontendRuntimeContributor;
use Capell\Frontend\Support\Assets\DefaultFrontendResourcePlanRenderer;
use Capell\Frontend\Support\Assets\FrontendAssetsService;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Capell\Frontend\Support\Assets\FrontendViteInputRegistry;
use Capell\Frontend\Support\Assets\PublicFrontendAssetUrl;
use Capell\Frontend\Support\Assets\ThemeMetaAssetContributor;
use Capell\Frontend\Support\Blade\BuildAssetDirective;
use Capell\Frontend\Support\Blade\FrontendAssetDirective;
use Capell\Frontend\Support\Blade\WireNavigateDirective;
use Capell\Frontend\Support\Cache\CacheInvalidationExecutor;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Cache\FragmentCacheDirective;
use Capell\Frontend\Support\Cache\FrontendCacheInvalidationObserver;
use Capell\Frontend\Support\Cache\FrontendCachePolicy;
use Capell\Frontend\Support\Cache\PageCacheInvalidator;
use Capell\Frontend\Support\Cache\PageHydrator;
use Capell\Frontend\Support\Cache\PageListingCache;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Cache\Resolvers\MediaTranslationCacheDependencyResolver;
use Capell\Frontend\Support\Cache\Resolvers\PageableTranslationCacheDependencyResolver;
use Capell\Frontend\Support\Cache\Resolvers\SiteTranslationCacheDependencyResolver;
use Capell\Frontend\Support\Cache\TranslationCacheDependencyRegistry;
use Capell\Frontend\Support\Components\FrontendComponentRegistry;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Capell\Frontend\Support\Error\ErrorPagePathResolver;
use Capell\Frontend\Support\Error\ErrorPageRegenerationQueue;
use Capell\Frontend\Support\Font\FontMimeTypeResolver;
use Capell\Frontend\Support\Fragments\EncryptedPublicFragmentReferenceCodec;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;
use Capell\Frontend\Support\Html\HtmlMinifier as VokuHtmlMinifier;
use Capell\Frontend\Support\Kernel\FrontendKernel;
use Capell\Frontend\Support\Kernel\Steps\LayoutResolverStep;
use Capell\Frontend\Support\Kernel\Steps\NormalizeDomainPathStep;
use Capell\Frontend\Support\Kernel\Steps\NotifySubscribersStep;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ParseUrlStep;
use Capell\Frontend\Support\Kernel\Steps\RegisterThemeViewsStep;
use Capell\Frontend\Support\Kernel\Steps\SetUrlGeneratorStep;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ThemeResolverStep;
use Capell\Frontend\Support\Links\PublicRouteAliasRegistry;
use Capell\Frontend\Support\Loader\DefaultSystemPageResolver;
use Capell\Frontend\Support\Loader\NullRedirectResolver;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\Frontend\Support\Maintenance\MaintenancePagePathResolver;
use Capell\Frontend\Support\ModelServing\NullRenderedModelTracker;
use Capell\Frontend\Support\Pagination\StatelessPaginationResolver;
use Capell\Frontend\Support\Render\BladeFrontendResponseRenderer;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\Render\LivewireFrontendResponseRenderer;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\Renderables\RenderableDynamicDataRegistry;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Capell\Frontend\Support\Routing\ReservedFrontendRequest;
use Capell\Frontend\Support\Routing\SiteUrlGenerator;
use Capell\Frontend\Support\Rules\Conditions\AuthStateCondition;
use Capell\Frontend\Support\Rules\Conditions\CampaignParameterCondition;
use Capell\Frontend\Support\Rules\Conditions\CookieCondition;
use Capell\Frontend\Support\Rules\Conditions\DateWindowCondition;
use Capell\Frontend\Support\Rules\Conditions\DomainCondition;
use Capell\Frontend\Support\Rules\Conditions\EnvironmentCondition;
use Capell\Frontend\Support\Rules\Conditions\LanguageCondition;
use Capell\Frontend\Support\Rules\Conditions\LayoutCondition;
use Capell\Frontend\Support\Rules\Conditions\LocaleCondition;
use Capell\Frontend\Support\Rules\Conditions\PageCondition;
use Capell\Frontend\Support\Rules\Conditions\PathCondition;
use Capell\Frontend\Support\Rules\Conditions\PermissionCondition;
use Capell\Frontend\Support\Rules\Conditions\QueryParameterCondition;
use Capell\Frontend\Support\Rules\Conditions\RoleCondition;
use Capell\Frontend\Support\Rules\Conditions\SessionFlagCondition;
use Capell\Frontend\Support\Rules\Conditions\SiteCondition;
use Capell\Frontend\Support\Rules\FrontendRuleConditionRegistry;
use Capell\Frontend\Support\Security\FilamentAdminAccessChecker;
use Capell\Frontend\Support\Security\FrontendUrlSignatureService;
use Capell\Frontend\Support\Security\HeadContentSanitizer;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\Static\StaticPageArtifactPathResolver;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\Frontend\Support\Tailwind\TailwindAssetsGenerator;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Capell\LayoutBuilder\Enums\LayoutWidgetTarget;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;
use Composer\InstalledVersions;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator as LaravelUrlGenerator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewFinderInterface;
use Livewire\Livewire;
use Override;
use Spatie\LaravelPackageTools\Package;

final class FrontendServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-frontend';

    public static string $packageName = 'capell-app/frontend';

    public function packageRegistered(): void
    {
        $this
            ->registerLoggingChannel()
            ->registerThemeRuntime()
            ->registerPackageMetadata();

        $this->app->singletonIf(
            CacheBypassResolver::class,
            NullCacheBypassResolver::class,
        );

        $this->app->singleton(
            UrlSignatureVerifierInterface::class,
            fn (): FrontendUrlSignatureService => new FrontendUrlSignatureService((string) config('app.key')),
        );
        $this->app->singleton(AssetsRegistryInterface::class, FrontendAssetsService::class);
        $this->app->singleton('capell.tailwind.generator', fn (Application $application): TailwindAssetsGenerator => new TailwindAssetsGenerator(
            $application->make(Filesystem::class),
        ));
        $this->app->alias('capell.tailwind.generator', TailwindAssetsGenerator::class);
        $this->app->singleton(FrontendComponentRegistryInterface::class, FrontendComponentRegistry::class);
        $this->app->singleton(PublicRouteAliasRegistry::class);
        $this->app->singleton(RenderableDynamicDataRegistry::class);
        $this->registerCoreFrontendComponents();
        $this->app->singleton(FrontendSettingsReaderInterface::class, FrontendSettingsReader::class);
        $this->app->singleton(SettingsMigrationProviderInterface::class, FrontendSettingsMigrationProvider::class);
        $this->app->singletonIf(MigrationFilesystemInterface::class, MigrationFilesystem::class);
        $this->app->singleton(FontMimeTypeResolverInterface::class, FontMimeTypeResolver::class);
        $this->app->singleton(HtmlMinifier::class, VokuHtmlMinifier::class);
        $this->app->singleton(PublicFragmentReferenceCodec::class, EncryptedPublicFragmentReferenceCodec::class);
        $this->app->scoped(
            PublicFragmentUrlResolverRegistry::class,
            fn (Application $application): PublicFragmentUrlResolverRegistry => new PublicFragmentUrlResolverRegistry(
                $application->tagged(PublicFragmentUrlResolver::TAG),
            ),
        );
        $this->app->singletonIf(FrontendResourcePlanRenderer::class, DefaultFrontendResourcePlanRenderer::class);
        $this->app->singletonIf(RedirectResolver::class, NullRedirectResolver::class);
        $this->app->bind(DefaultSystemPageResolver::class);
        $this->app->tag(DefaultSystemPageResolver::class, SystemPageResolver::TAG);
        $this->app->singleton(MaintenanceManifestStore::class);
        $this->app->singleton(MaintenancePagePathResolver::class);
        $this->app->singleton(ErrorPageManifestStore::class);
        $this->app->singleton(ErrorPagePathResolver::class);
        $this->app->singleton(ErrorPageFallbackManifestStore::class);
        $this->app->singleton(ErrorPageRegenerationQueue::class);
        $this->app->scoped(FrontendResponseRendererRegistry::class);
        $this->app->singleton(StatelessPaginationResolver::class);
        $this->app->singleton(PublicViewQueryGuard::class);
        $this->app->singleton(RenderHookRegistry::class);
        $this->app->singleton(FrontendHookRegistrar::class);
        $this->app->singleton(FrontendRuleConditionRegistry::class);
        $this->app->singleton(ReservedFrontendPathRegistry::class);
        $this->app->singleton(ReservedFrontendDomainRegistry::class);
        $this->app->singleton(ReservedFrontendRequest::class);
        $this->app->singleton(FrontendLogger::class);
        $this->app->singleton(ThemePreviewRendererInterface::class, FrontendThemePreviewRenderer::class);
        $this->app->scoped(PublicFrontendAssetUrl::class);

        $this->registerAssetOptimizationBindings();
        $this->registerCacheInvalidationBindings();

        $this->registerFrontendContextBindings();

        // Ensure FileViewFinder resolves to the framework's configured view finder
        $this->app->alias('view.finder', ViewFinderInterface::class);
        $this->app->bind(FileViewFinder::class, function (Application $app): FileViewFinder {
            /** @var FileViewFinder $finder */
            $finder = $app->make('view.finder');

            return $finder;
        });

        $this->app->scoped(FrontendKernelInterface::class, function (Application $app): FrontendKernelInterface {
            $steps = config('frontend.kernel.steps', [
                ParseUrlStep::class,
                SiteResolveStep::class,
                SetUrlGeneratorStep::class,
                NormalizeDomainPathStep::class,
                PageResolveStep::class,
                LayoutResolverStep::class,
                ThemeResolverStep::class,
                RegisterThemeViewsStep::class,
                NotifySubscribersStep::class,
            ]);

            return new FrontendKernel(
                $app->make(Pipeline::class),
                $steps,
                $app->make(FrontendState::class),
            );
        });

        $this->app->singleton(ThemeViewRegistrar::class);
        $this->app->singleton(ThemeChainResolver::class);
        $this->app->singleton(FrontendCachePolicy::class);
        $this->app->singleton(FrontendRouteMiddlewareRegistry::class);
        $this->app->scoped(PageCacheInvalidator::class);
        $this->app->alias(PageCacheInvalidator::class, 'capell.frontend.page-cache-invalidator');
        $this->app->scoped(PageListingCache::class);
        $this->app->scoped(PageModelCache::class);
        $this->app->scoped(PublicPageRenderDataCache::class);
        $this->app->scoped(PageHydrator::class);
        $this->app->singleton(StaticPageArtifactStore::class);
        $this->app->singleton(StaticPageArtifactPathResolver::class);
        $this->app->singleton(OnFrontendContextResolved::class);
        $this->app->singleton(FragmentCache::class, fn (Application $app): FragmentCache => new FragmentCache($app->make(Repository::class)));
        $this->app->alias(FragmentCache::class, 'capell-frontend.fragment-cache');
        $this->app->tag([
            CacheInvalidationRegistry::class,
            FragmentCacheDirective::class,
            ThemeViewRegistrar::class,
        ], Resettable::TAG);

        $this->app->scoped(FragmentCacheDirective::class);
        $this->app->scoped(FrontendAssetDirective::class);
        $this->app->scoped(WireNavigateDirective::class);

        $this->app->afterResolving(FrontendRuleConditionRegistry::class, function (FrontendRuleConditionRegistry $registry): void {
            $registry->register(AuthStateCondition::class);
            $registry->register(CampaignParameterCondition::class);
            $registry->register(CookieCondition::class);
            $registry->register(DateWindowCondition::class);
            $registry->register(DomainCondition::class);
            $registry->register(EnvironmentCondition::class);
            $registry->register(LanguageCondition::class);
            $registry->register(LayoutCondition::class);
            $registry->register(LocaleCondition::class);
            $registry->register(PageCondition::class);
            $registry->register(PathCondition::class);
            $registry->register(PermissionCondition::class);
            $registry->register(QueryParameterCondition::class);
            $registry->register(RoleCondition::class);
            $registry->register(SessionFlagCondition::class);
            $registry->register(SiteCondition::class);
        });

        $this->app->afterResolving(
            FrontendResponseRendererRegistry::class,
            function (FrontendResponseRendererRegistry $registry): void {
                $registry->registerClass(FrontendRuntime::Blade, BladeFrontendResponseRenderer::class);
                $registry->registerClass(FrontendRuntime::Livewire, LivewireFrontendResponseRenderer::class);
            },
        );

        $this->app->bind(LaravelUrlGenerator::class, function (Application $application): LaravelUrlGenerator {
            $routes = $application->make(Router::class)->getRoutes();
            $request = $application->make('request');

            if (config('capell-frontend.use_site_domain_for_urls', false)) {
                return new SiteUrlGenerator($routes, $request);
            }

            return new LaravelUrlGenerator($routes, $request);
        });
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasCommands([
                InstallCommand::class,
                UpgradeCommand::class,
                AfterInstallCommand::class,
                GenerateTailwindAssetsCommand::class,
                GenerateHtmlCommand::class,
                GenerateErrorPagesCommand::class,
            ])
            ->hasConfigFile()
            ->hasTranslations()
            ->hasRoute('web')
            ->hasViews('capell');
    }

    #[Override]
    public function registeringPackage(): void
    {
        parent::registeringPackage();

        $this->registerMiddlewareAliases();
        $this->registerErrorViewFallbackPath();
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        return $this
            ->registerPublishCommands()
            ->registerTailwindAssets()
            ->registerAboutInfo()
            ->registerBladeComponents()
            ->registerBlazeComponents()
            ->registerLivewireComponents()
            ->registerBladeDirectives()
            ->registerPaginateRoute()
            ->configureVite()
            ->registerEventListeners()
            ->registerFrontendCacheInvalidationObservers()
            ->scheduleSiteCheck()
            ->registerSettingsSchemas()
            ->registerViewComposers();
    }

    private function registerAssetOptimizationBindings(): void
    {
        $this->app->singleton(FrontendResourceRegistry::class);
        $this->app->singleton(FrontendPackageDependencyRegistry::class);
        $this->app->singleton(FrontendViteInputRegistry::class);
        $this->app->scoped('capell.frontend.resource-group-options', fn (Application $application): callable => static fn (): array => collect($application->make(FrontendResourceRegistry::class)->all())
            ->mapWithKeys(fn (FrontendResourceGroupData $group, string $key): array => [
                $key => $group->label,
            ])
            ->all());
        $this->app->scoped('capell.frontend.page-resource-diagnostics', fn (): callable => BuildPageFrontendResourceDiagnosticsAction::run(...));
        $this->app->scoped('capell.frontend.resource-debug-overlay-payload', fn (): callable => BuildFrontendResourceDebugOverlayPayloadAction::run(...));
        $this->app->scoped(ThemeMetaAssetContributor::class);
        $this->app->tag([CoreFrontendRuntimeContributor::class, ThemeMetaAssetContributor::class], FrontendResourceContributor::TAG);
    }

    private function registerCacheInvalidationBindings(): void
    {
        $this->app->scoped(CacheInvalidationExecutor::class);
        $this->app->scoped(PageableTranslationCacheDependencyResolver::class);
        $this->app->scoped(MediaTranslationCacheDependencyResolver::class);
        $this->app->scoped(SiteTranslationCacheDependencyResolver::class);
        $this->app->tag([
            PageableTranslationCacheDependencyResolver::class,
            MediaTranslationCacheDependencyResolver::class,
            SiteTranslationCacheDependencyResolver::class,
        ], TranslationCacheDependencyResolver::TAG);
        $this->app->scoped(
            TranslationCacheDependencyRegistry::class,
            fn (Application $app): TranslationCacheDependencyRegistry => new TranslationCacheDependencyRegistry(
                $app->tagged(TranslationCacheDependencyResolver::TAG),
            ),
        );
        $this->app->scoped(CacheInvalidationRegistry::class);
    }

    private function registerFrontendContextBindings(): void
    {
        $this->app->singleton(AdminAccessCheckerInterface::class, FilamentAdminAccessChecker::class);
        $this->app->scoped(FrontendState::class, fn (): FrontendState => new FrontendState);
        $this->app->scoped(FrontendContextReader::class, fn (Application $app): FrontendState => $app->make(FrontendState::class));
        $this->app->scopedIf(RenderedModelTracker::class, fn (): RenderedModelTracker => new NullRenderedModelTracker);
        $this->app->alias(RenderedModelTracker::class, 'capell.frontend.retrieved-model-store');
        $this->app->scoped(
            'capell.frontend.layout-container-width-resolver',
            fn (): callable => GetLayoutContainerWidthAction::run(...),
        );

        $this->registerDefaultReservedFrontendPaths();
        $this->registerDefaultReservedFrontendDomains();
    }

    /**
     * Laravel resolves `errors::{code}` by mapping every entry in
     * `config('view.paths')` to `{path}/errors` (see
     * Illuminate\Foundation\Exceptions\RegisterErrorViewPaths), re-reading it
     * fresh on every exception render. Appending this package's views here
     * makes packages/frontend/resources/views/errors the default branded
     * 401/403/404/419/429/500/503 pages for every Capell site, so consuming
     * apps (capell-app included) no longer need their own copies.
     */
    private function registerErrorViewFallbackPath(): void
    {
        config(['view.paths' => [
            ...config('view.paths', []),
            __DIR__ . '/../../resources/views',
        ]]);
    }

    private function registerAboutInfo(): self
    {
        if ($this->app->runningInConsole() && (class_exists(AboutCommand::class) && class_exists(InstalledVersions::class))) {
            AboutCommand::add('Capell', [
                self::$name => fn (): ?string => CapellCore::getInstalledPrettyVersion(self::$packageName),
            ]);
        }

        return $this;
    }

    private function registerMiddlewareAliases(): self
    {
        Route::aliasMiddleware('frontend.etag', ETagMiddleware::class);
        Route::aliasMiddleware('frontend.asset-optimization', AssetOptimizationMiddleware::class);
        Route::aliasMiddleware('frontend.anonymous_cacheable_render', PreventAuthenticatedFrontendRenderingWhenHtmlCacheable::class);
        Route::aliasMiddleware('frontend.rendering_strategy', RenderingStrategyMiddleware::class);
        Route::aliasMiddleware('frontend.resolve', ResolveFrontendMiddleware::class);
        Route::aliasMiddleware('frontend.maintenance', ServeStaticMaintenancePage::class);
        Route::aliasMiddleware('workspace.context', NullWorkspaceContextMiddleware::class);

        return $this;
    }

    private function registerPaginateRoute(): self
    {
        // get vendor
        $vendor = base_path('vendor');
        $this->loadTranslationsFrom($vendor . '/michaloravec/laravel-paginateroute/resources/lang', 'paginateroute');

        return $this;
    }

    private function registerLivewireComponents(): self
    {
        if (! $this->app->bound('livewire.finder')) {
            return $this;
        }

        $this->registerDefaultPageLivewireComponent();

        $configuredComponents = config('capell-frontend.livewire_components', []);

        foreach ($configuredComponents as $name => $component) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_string($component)) {
                continue;
            }

            Livewire::component($name, $component);
        }

        if (class_exists(LayoutWidgetRegistry::class)) {
            $registry = resolve(LayoutWidgetRegistry::class);

            foreach ($registry->allForTarget(LayoutWidgetTarget::FrontendLivewire) as $name => $component) {
                Livewire::component($name, $component);
            }
        }

        if ($this->isLivewireV3() === false) {
            Livewire::addNamespace(
                namespace: 'capell',
                classNamespace: 'Capell\\Frontend\\Livewire',
                classPath: __DIR__ . '/../Livewire',
                classViewPath: __DIR__ . '/../../resources/views/livewire',
            );
        }

        return $this;
    }

    private function registerBladeComponents(): self
    {
        $configuredComponents = config('capell-frontend.blade_components', []);

        foreach ($configuredComponents as $name => $component) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_string($component)) {
                continue;
            }

            $this->registerBladeComponentAlias($name, $component);
        }

        if (class_exists(LayoutWidgetRegistry::class)) {
            $registry = resolve(LayoutWidgetRegistry::class);

            foreach ($registry->allForTarget(LayoutWidgetTarget::FrontendBlade) as $name => $component) {
                $this->registerBladeComponentAlias($name, $component);
            }
        }

        return $this;
    }

    private function registerBlazeComponents(): self
    {
        RegisterBlazeOptimizedViewsAction::run(__DIR__ . '/../../resources/views/components/layout/index.blade.php');

        return $this;
    }

    private function registerBladeComponentAlias(string $name, string $component): void
    {
        Blade::component($component, $name);
    }

    private function configureVite(): self
    {
        if (class_exists(Vite::class) && config('capell-frontend.public_aggressive_prefetch', false)) {
            Vite::useAggressivePrefetching();
        }

        return $this;
    }

    private function scheduleSiteCheck(): self
    {
        $schedulePageCleaner = config('capell-frontend.schedule_page_cleaner', 'daily');

        if (is_string($schedulePageCleaner) && $schedulePageCleaner !== '') {
            $validFrequencies = [
                'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes', 'everyFiveMinutes',
                'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly', 'everyTwoHours',
                'everyThreeHours', 'everyFourHours', 'everySixHours', 'daily', 'twiceDaily', 'weekly',
                'monthly', 'quarterly', 'yearly',
            ];

            if (in_array($schedulePageCleaner, $validFrequencies, true)) {
                $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($schedulePageCleaner): void {
                    $method = $schedulePageCleaner;
                    // Use explicit method calls to avoid dynamic invocation
                    $event = $schedule->command('capell:frontend-site-check');
                    match ($method) {
                        'everyMinute' => $event->everyMinute(),
                        'everyTwoMinutes' => $event->everyTwoMinutes(),
                        'everyThreeMinutes' => $event->everyThreeMinutes(),
                        'everyFourMinutes' => $event->everyFourMinutes(),
                        'everyFiveMinutes' => $event->everyFiveMinutes(),
                        'everyTenMinutes' => $event->everyTenMinutes(),
                        'everyFifteenMinutes' => $event->everyFifteenMinutes(),
                        'everyThirtyMinutes' => $event->everyThirtyMinutes(),
                        'hourly' => $event->hourly(),
                        'everyTwoHours' => $event->everyTwoHours(),
                        'everyThreeHours' => $event->everyThreeHours(),
                        'everyFourHours' => $event->everyFourHours(),
                        'everySixHours' => $event->everySixHours(),
                        'daily' => $event->daily(),
                        'twiceDaily' => $event->twiceDaily(),
                        'weekly' => $event->weekly(),
                        'monthly' => $event->monthly(),
                        'quarterly' => $event->quarterly(),
                        'yearly' => $event->yearly(),
                        default => Log::warning('Invalid schedule method: ' . $method),
                    };
                });
            } else {
                Log::warning('Invalid schedule frequency: ' . $schedulePageCleaner);
            }
        }

        return $this;
    }

    private function registerPackageMetadata(): self
    {
        CapellCore::registerPackage(
            self::$packageName,
            type: self::getType(),
            serviceProviderClass: self::class,
            path: realpath(__DIR__ . '/../..'),
            version: CapellCore::getInstalledPrettyVersion(self::$packageName),
        );

        return $this;
    }

    private function registerPublishCommands(): self
    {
        // Publish under both the Capell-specific tag and Laravel's conventional
        // `laravel-assets` group, so `php artisan vendor:publish --tag=laravel-assets`
        // (the standard skeleton post-update-cmd / deploy hook) also republishes
        // the prebuilt capell-frontend assets alongside framework + Filament assets.
        $this->publishes([
            $this->package->basePath('/../publishes/build') => public_path('vendor/capell-frontend'),
        ], ['capell-frontend-assets', 'laravel-assets']);

        $this->publishes([
            $this->package->basePath('/../publishes/config/') => config_path(),
        ], 'capell-frontend-publish');

        return $this;
    }

    private function registerTailwindAssets(): self
    {
        CapellCore::registerVendorAsset(
            VendorAssetData::tailwindImport('resources/css/capell-frontend.css', self::$packageName),
        );

        return $this;
    }

    private function registerEventListeners(): self
    {
        Event::listen(FrontendContextResolved::class, [OnFrontendContextResolved::class, 'handle']);
        Event::listen(PageSavedEvent::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleSaved']);
        Event::listen(PageDeletedEvent::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleDeleted']);
        Event::listen(PageUrlChanged::class, [PurgeCdnCacheOnPageChangeListener::class, 'handlePageUrlChanged']);
        Event::listen(FrontendSurrogateKeysInvalidated::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleSurrogateKeys']);

        Event::listen('eloquent.created: *', [ErrorPageModelInvalidationObserver::class, 'createdFromEvent']);
        Event::listen('eloquent.updated: *', [ErrorPageModelInvalidationObserver::class, 'updatedFromEvent']);
        Event::listen('eloquent.deleted: *', [ErrorPageModelInvalidationObserver::class, 'deletedFromEvent']);

        return $this;
    }

    private function registerFrontendCacheInvalidationObservers(): self
    {
        foreach ([
            Language::class,
            Layout::class,
            Media::class,
            PageUrl::class,
            Site::class,
            SiteDomain::class,
            Theme::class,
            Translation::class,
        ] as $modelClass) {
            $modelClass::observe(FrontendCacheInvalidationObserver::class);
        }

        return $this;
    }

    private function registerThemeRuntime(): self
    {
        $this->app->afterResolving(
            RenderHookRegistry::class,
            function (RenderHookRegistry $registry): void {
                $registry->register(
                    RenderHookLocation::HeadClose,
                    function (): string {
                        if (! $this->app->bound(ThemeRuntimeSettings::class)) {
                            return '';
                        }

                        $settings = $this->app->make(ThemeRuntimeSettings::class);
                        $theme = $this->app->bound(FrontendContextReader::class)
                            ? $this->app->make(FrontendContextReader::class)->theme()
                            : null;
                        $activeTheme = $theme instanceof Theme
                            ? $theme->key
                            : $settings->activeTheme();
                        $activePreset = $theme instanceof Theme
                            ? data_get($theme->meta, 'editor.preset.active', $settings->activePreset())
                            : $settings->activePreset();
                        $themeOverrides = $settings->themeOverrides();

                        if ($theme instanceof Theme) {
                            $savedTokens = data_get($theme->meta, 'editor.tokens', []);

                            if (is_array($savedTokens)) {
                                $themeOverrides[$theme->key] = [
                                    ...($themeOverrides[$theme->key] ?? []),
                                    ...collect($savedTokens)
                                        ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value))
                                        ->all(),
                                ];
                            }
                        }

                        if (! is_string($activePreset) || $activePreset === '') {
                            $activePreset = $settings->activePreset();
                        }

                        if (! $this->app->make(ThemeRegistry::class)->has($activeTheme)) {
                            return '';
                        }

                        $runtime = ResolveThemeRuntimeAction::run(
                            activeTheme: $activeTheme,
                            activePreset: $activePreset,
                            brand: $settings->brandProfile(),
                            themeOverrides: $themeOverrides,
                        );

                        if ($runtime->tokenCssPath === null) {
                            return '';
                        }

                        if (is_file($runtime->tokenCssPath) && is_readable($runtime->tokenCssPath)) {
                            $css = file_get_contents($runtime->tokenCssPath);

                            if (is_string($css) && $css !== '') {
                                return '<style data-capell-theme-tokens>' . HeadContentSanitizer::css($css) . '</style>';
                            }
                        }

                        return '<link rel="stylesheet" href="' . e($this->app->make(ThemeTokenStore::class)->publicUrl($runtime->tokenCssPath)) . '">';
                    },
                );
            },
        );

        return $this;
    }

    private function registerSettingsSchemas(): self
    {
        $registry = resolve(SettingsSchemaRegistry::class);

        $registry->registerSettingsClass('frontend', FrontendSettings::class);
        $registry->registerMetadata(new SettingsGroupMetadata(
            group: 'frontend',
            label: 'capell-admin::generic.frontend_settings',
            icon: Heroicon::OutlinedGlobeAlt,
            navigationGroup: 'capell-admin::navigation.group_system',
            navigationSort: 92,
            packageName: self::$packageName,
        ));
        $registry->register('frontend', FrontendSettingsSchema::class);

        return $this;
    }

    private function registerLoggingChannel(): self
    {
        $channels = config('logging.channels', []);

        if (! array_key_exists('capell', $channels)) {
            Config::set(
                'logging.channels.capell',
                [
                    'driver' => 'single',
                    'path' => storage_path('logs/capell.log'),
                    'level' => 'debug',
                ],
            );
        }

        return $this;
    }

    private function registerBladeDirectives(): self
    {
        Blade::directive('buildAssets', fn (string $expression) => resolve(BuildAssetDirective::class)->compile($expression));
        Blade::directive('cache', fn (string $expression) => resolve(FragmentCacheDirective::class)->compile($expression));
        Blade::directive('endcache', fn () => resolve(FragmentCacheDirective::class)->compileEnd());
        Blade::directive('frontendAsset', fn (string $expression): string => resolve(FrontendAssetDirective::class)->compile($expression));
        Blade::directive('wireNavigate', fn (): string => resolve(WireNavigateDirective::class)->compile());

        return $this;
    }

    private function registerViewComposers(): self
    {
        View::composer('capell::app', RenderingStrategyViewComposer::class);

        return $this;
    }

    private function registerCoreFrontendComponents(): void
    {
        $this->callAfterResolving(FrontendComponentRegistryInterface::class, function (FrontendComponentRegistryInterface $registry): void {
            $registry
                ->register(
                    key: AssetComponentEnum::Card->value,
                    component: 'capell::asset.index',
                    aliases: ['capell::asset.index'],
                )
                ->register(
                    key: AssetComponentEnum::Media->value,
                    component: 'capell::media.asset',
                    aliases: ['capell::media.asset'],
                )
                ->register(
                    key: AssetComponentEnum::Page->value,
                    component: 'capell::page.asset',
                    aliases: ['capell::page.asset'],
                )
                ->register(
                    key: AssetComponentEnum::Tile->value,
                    component: 'capell::asset.tile',
                    aliases: ['capell::asset.tile'],
                );
        });
    }

    private function registerDefaultReservedFrontendPaths(): void
    {
        $registry = $this->app->make(ReservedFrontendPathRegistry::class);

        foreach (['admin', 'api', 'install', 'livewire', 'storage', '_capell', '_clockwork', '_debugbar'] as $prefix) {
            $registry->reservePrefix($prefix);
        }

        foreach (config('capell-frontend.route.reserved_prefixes', []) as $prefix) {
            if (is_string($prefix)) {
                $registry->reservePrefix($prefix);
            }
        }

        foreach (config('capell-frontend.route.reserved_exact_paths', []) as $path) {
            if (is_string($path)) {
                $registry->reserveExact($path);
            }
        }
    }

    private function registerDefaultReservedFrontendDomains(): void
    {
        $registry = $this->app->make(ReservedFrontendDomainRegistry::class);

        foreach (config('capell-frontend.route.reserved_domains', []) as $domain) {
            if (is_string($domain)) {
                $registry->reserve($domain);
            }
        }
    }

    private function registerDefaultPageLivewireComponent(): void
    {
        Livewire::component(LivewirePageComponentEnum::Default->value, Page::class);
    }
}
