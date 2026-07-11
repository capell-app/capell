<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Plugin;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\AvatarProviders\InlineSvgAvatarProvider;
use Capell\Admin\Filament\Pages\AbstractPackageSettingsPage;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
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
use Capell\Admin\Http\Middleware\EnforceLockdownAdminAccess;
use Capell\Admin\Http\Middleware\ProfileAdminRequest;
use Capell\Admin\Http\Middleware\RedirectToInstallerWhenCapellIsNotInstalled;
use Capell\Admin\Http\Middleware\SetAdminLocale;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Closure;
use CmsMulti\FilamentClearCache\FilamentClearCachePlugin;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament as FilamentFacade;
use Filament\FilamentManager;
use Filament\Navigation\NavigationManager;
use Filament\Pages\Page as FilamentPage;
use Filament\Pages\SettingsPage as FilamentSettingsPage;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

class CapellAdminPlugin implements Plugin
{
    public const string ID = 'capell-admin';

    public static function make(): static
    {
        return resolve(static::class);
    }

    public static function get(): FilamentManager|Plugin
    {
        return filament(resolve(static::class)->getId());
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function boot(Panel $panel): void
    {
        $this->registerNavigationGroups($panel);

        Table::configureUsing(function (Table $table): void {
            $table->defaultSort(function (BuilderContract $query, string $direction) use ($table): BuilderContract {
                $model = $query->getModel();

                if ($model->usesTimestamps()) {
                    return $query->orderBy($model->qualifyColumn('updated_at'), 'desc');
                }

                $column = $table->getColumn('name') instanceof Column ? 'name' : $model->getKeyName();
                $sortDirection = $direction === 'asc' ? 'asc' : 'desc';

                return $query->orderBy($model->qualifyColumn($column), $sortDirection);
            });
        });

        CreateAction::configureUsing(function (CreateAction $action): void {
            $action->forceRenderAfterCreateAnother();
        });
    }

    public function discoverConfigurators(string $in, string $for): static
    {
        return $this;
    }

    public function register(Panel $panel): void
    {
        /** @var view-string $logoView */
        $logoView = 'capell-admin::img.logo';
        /** @var view-string $sitesView */
        $sitesView = 'capell-admin::components.header.sites';
        /** @var view-string $languageSelectView */
        $languageSelectView = 'capell-admin::components.user-menu.admin-language-select';
        /** @var view-string $lockdownBannerView */
        $lockdownBannerView = 'capell-admin::components.header.lockdown-banner';
        /** @var view-string $headerActionsView */
        $headerActionsView = 'capell-admin::components.header.actions';

        $panel->authMiddleware([
            RedirectToInstallerWhenCapellIsNotInstalled::class,
            EnforceLockdownAdminAccess::class,
            SetAdminLocale::class,
            ProfileAdminRequest::class,
        ]);

        if (! CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()) {
            $pages = CapellAdmin::getAdminSurfaceRegistry()->pages();

            $panel->pages($pages);

            return;
        }

        $this->registerAssets()
            ->registerInstalledPackageAdminProviders()
            ->registerConfigurators()
            ->registerPlugins($panel)
            ->synchronizeAdminSurface($panel)
            ->registerNavigationItems($panel)
            ->registerDashboardFilamentWidgets()
            ->registerSettings($panel);

        app()->booted(function () use ($panel): void {
            $this->registerInstalledPackageAdminProviders()
                ->registerConfigurators()
                ->synchronizeAdminSurface($panel);
        });

        $panel
            ->brandName('Capell')
            ->brandLogo(fn (): View => view($logoView))
            ->brandLogoHeight('2.2rem')
            ->defaultAvatarProvider(InlineSvgAvatarProvider::class)
            ->userMenuItems($this->getUserMenuItems())
            ->renderHook(
                name: PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                hook: fn (): View => view(
                    $sitesView,
                    ['sites' => SiteLoader::getSites()],
                ),
            )
            ->renderHook(
                name: PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                hook: fn (): View => view($headerActionsView),
            )
            ->renderHook(
                name: PanelsRenderHook::USER_MENU_PROFILE_AFTER,
                hook: fn (): View => view($languageSelectView),
            )
            ->renderHook(
                name: PanelsRenderHook::BODY_START,
                hook: fn (): string => Blade::render(
                    '<x-capell-admin::keyboard-shortcuts />',
                ),
            )
            ->renderHook(
                name: PanelsRenderHook::BODY_START,
                hook: fn (): View => view($lockdownBannerView),
            );
    }

    public function synchronizeCurrentPanelAdminSurface(): void
    {
        $panel = FilamentFacade::getCurrentPanel();

        if (! $panel instanceof Panel) {
            return;
        }

        $this->registerInstalledPackageAdminProviders()
            ->registerConfigurators()
            ->synchronizeAdminSurface($panel);

        app()->forgetInstance(NavigationManager::class);
    }

    protected function registerPages(Panel $panel): self
    {
        $pages = array_merge(
            CapellAdmin::getAdminSurfaceRegistry()->pages(),
            $this->discoverInstalledPackageFilamentPages(),
        );

        $panel->pages(array_values(array_unique($pages)));

        return $this;
    }

    protected function synchronizeAdminSurface(Panel $panel): self
    {
        return $this
            ->registerPages($panel)
            ->registerResources($panel)
            ->registerWidgets($panel);
    }

    protected function registerPlugins(Panel $panel): self
    {
        $panel->resources([
            RoleResource::class,
        ]);

        if (! $panel->hasPlugin('filament-shield')) {
            $panel->plugin(
                FilamentShieldPlugin::make()
                    ->navigationGroup(fn (): string => __('capell-admin::navigation.group_users'))
                    ->navigationIcon(Heroicon::OutlinedKey)
                    ->activeNavigationIcon(Heroicon::Key)
                    ->navigationSort(4)
                    ->globallySearchable(false),
            );
        }

        if (! $panel->hasPlugin('spatie-laravel-translatable')) {
            $panel->plugin(SpatieTranslatablePlugin::make());
        }

        if (! $panel->hasPlugin('filament-clear-cache')) {
            $panel->plugin(FilamentClearCachePlugin::make());
        }

        /** @var iterable<AdminPanelExtender> $extenders */
        $extenders = app()->tagged(AdminPanelExtender::TAG);

        foreach ($extenders as $extender) {
            $extender->extend($panel);
        }

        return $this;
    }

    protected function getPublishedDirectory(): string
    {
        $dir = realpath(__DIR__ . '/../../../publishes');

        throw_if(in_array($dir, ['', '0', false], true), RuntimeException::class, 'Publish directory not found.');

        return $dir;
    }

    protected function registerAssets(): self
    {
        $publishDir = self::getPublishedDirectory();

        FilamentAsset::register([
            Js::make(
                'rich-content-plugins/highlight',
                $publishDir . '/build/js/filament/rich-content-plugins/highlight.js',
            )
                ->loadedOnRequest(),
            AlpineComponent::make('html-code-editor', $publishDir . '/build/js/components/html-code-editor.js'),
            AlpineComponent::make('capell-keyboard-shortcuts', $publishDir . '/build/js/components/keyboard-shortcuts.js'),
            AlpineComponent::make('capell-content-lock-heartbeat', $publishDir . '/build/js/components/content-lock-heartbeat.js'),
        ], package: 'capell-admin');

        return $this;
    }

    /** @return array<string, Action|Closure> */
    private function getUserMenuItems(): array
    {
        $items = [];

        foreach (array_keys(CapellAdmin::getUserMenuItemDefinitions()) as $key) {
            $items[$key] = fn (): Action => CapellAdmin::getUserMenuItems(auth()->user())[$key] ?? Action::make($key)->hidden();
        }

        return array_merge($items, [
            'profile' => Action::make('profile')
                ->label(fn (): string => (string) data_get(auth()->user(), 'name', __('capell-admin::generic.profile')))
                ->url(function (): ?string {
                    $user = auth()->user();
                    if ($user === null || ! UserResource::can('edit', $user)) {
                        return null;
                    }

                    return UserResource::getUrl('edit', ['record' => auth()->id()]);
                }),
        ]);
    }

    private function registerNavigationItems(Panel $panel): self
    {
        $panel->navigationItems(array_merge(
            $panel->getNavigationItems(),
            CapellAdmin::getNavigationItems(),
        ));

        return $this;
    }

    private function registerNavigationGroups(Panel $panel): self
    {
        $navigationGroups = new ReflectionProperty($panel, 'navigationGroups');
        $navigationGroups->setValue($panel, []);

        $panel->navigationGroups(CapellAdmin::getNavigationGroups());

        return $this;
    }

    private function registerConfigurators(): self
    {
        foreach (ConfiguratorTypeEnum::getAllConfigurators() as $type => $configurators) {
            foreach ($configurators as $configuratorClass) {
                CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator(
                    class: $configuratorClass,
                    group: $type,
                    name: $configuratorClass::getKey(),
                ));
            }
        }

        return $this;
    }

    private function registerResources(Panel $panel): self
    {
        /** @var array<class-string<resource>> $resources */
        $resources = array_values(array_unique(array_merge(
            $this->filterPanelClasses($panel->getResources()),
            CapellAdmin::getAdminSurfaceRegistry()->resources(),
            $this->discoverInstalledPackageFilamentResources(),
        )));

        $this->replacePanelResources($panel, $this->filterPanelClasses($resources));

        return $this;
    }

    private function registerWidgets(Panel $panel): self
    {
        /** @var list<class-string<Widget>> $widgets */
        $widgets = CapellAdmin::getAdminSurfaceRegistry()->widgets();

        $panel->widgets($widgets);

        return $this;
    }

    /**
     * @template T of object
     *
     * @param  array<class-string<T>>  $classes
     * @return list<class-string<T>>
     */
    private function filterPanelClasses(array $classes): array
    {
        return array_values(collect($classes)
            ->filter(static fn (string $class): bool => class_exists($class))
            ->filter(static fn (string $class): bool => ! method_exists($class, 'shouldRegisterWithPanel')
                || $class::shouldRegisterWithPanel())
            ->values()
            ->all());
    }

    /**
     * @param  list<class-string<resource>>  $resources
     */
    private function replacePanelResources(Panel $panel, array $resources): void
    {
        $panelResources = new ReflectionProperty($panel, 'resources');
        $panelResources->setValue($panel, $resources);
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

    private function registerSettings(Panel $panel): self
    {
        $panel->sidebarCollapsibleOnDesktop(fn (): bool => CapellAdmin::settings()->sidebar_collapsible === SidebarCollapseEnum::Collapsible)
            ->sidebarFullyCollapsibleOnDesktop(fn (): bool => CapellAdmin::settings()->sidebar_collapsible === SidebarCollapseEnum::FullyCollapsible);

        return $this;
    }

    private function registerInstalledPackageAdminProviders(): self
    {
        foreach (CapellCore::getInstalledPackages() as $package) {
            $adminProvider = $this->resolveAdminProviderClass($package);
            if ($adminProvider === null) {
                continue;
            }

            if ($this->isProviderLoaded($adminProvider)) {
                continue;
            }

            app()->register($adminProvider);
        }

        return $this;
    }

    /**
     * @return class-string<ServiceProvider>|null
     */
    private function resolveAdminProviderClass(PackageData $package): ?string
    {
        $provider = $package->serviceProviderClass;

        if ($provider === null) {
            return null;
        }

        $adminProvider = (string) str($provider)
            ->beforeLast('\\')
            ->append('\\AdminServiceProvider');

        if (! is_subclass_of($adminProvider, ServiceProvider::class)) {
            return null;
        }

        /** @var class-string<ServiceProvider> $adminProvider */
        return $adminProvider;
    }

    private function isProviderLoaded(string $provider): bool
    {
        if (app()->providerIsLoaded($provider)) {
            return true;
        }

        return array_key_exists($provider, app()->getLoadedProviders());
    }

    /**
     * @return list<class-string<FilamentPage>>
     */
    private function discoverInstalledPackageFilamentPages(): array
    {
        $pages = $this->discoverInstalledPackageFilamentClasses(
            subPath: 'Filament/Pages',
            pattern: '*Page.php',
            baseClass: FilamentPage::class,
        );
        $pages = array_values(array_filter(
            $pages,
            fn (string $page): bool => ! is_subclass_of($page, AbstractPackageSettingsPage::class)
                && ! is_subclass_of($page, FilamentSettingsPage::class),
        ));

        foreach ($pages as $page) {
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page($page));
        }

        /** @var list<class-string<FilamentPage>> $registeredPages */
        $registeredPages = CapellAdmin::getAdminSurfaceRegistry()->pages();

        return $registeredPages;
    }

    /**
     * @return list<class-string<resource>>
     */
    private function discoverInstalledPackageFilamentResources(): array
    {
        $resources = $this->discoverInstalledPackageFilamentClasses(
            subPath: 'Filament/Resources',
            pattern: '*/*Resource.php',
            baseClass: Resource::class,
        );

        foreach ($resources as $resource) {
            $type = (string) str(class_basename($resource))->beforeLast('Resource');

            if ($type !== '') {
                CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
                    class: $resource,
                    group: $type,
                ));
            }
        }

        /** @var list<class-string<resource>> $registeredResources */
        $registeredResources = CapellAdmin::getAdminSurfaceRegistry()->resources();

        return $registeredResources;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $baseClass
     * @return list<class-string<T>>
     */
    private function discoverInstalledPackageFilamentClasses(string $subPath, string $pattern, string $baseClass): array
    {
        $filesystem = resolve(Filesystem::class);
        $classes = [];

        foreach (CapellCore::getInstalledPackages() as $package) {
            if (! is_string($package->path)) {
                continue;
            }

            $namespace = $this->resolvePackageNamespace($package);

            if ($namespace === null) {
                continue;
            }

            $directory = $package->path . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subPath);

            if (! $filesystem->isDirectory($directory)) {
                continue;
            }

            foreach ($filesystem->glob($directory . DIRECTORY_SEPARATOR . $pattern) as $path) {
                $class = $this->classFromPackagePath($namespace, $package->path, $path);
                if ($class === null) {
                    continue;
                }

                if (! is_subclass_of($class, $baseClass)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isAbstract()) {
                    continue;
                }

                /** @var class-string<T> $class */
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    private function resolvePackageNamespace(PackageData $package): ?string
    {
        $provider = $package->serviceProviderClass;

        if ($provider === null) {
            return null;
        }

        if (str_contains($provider, '\\Providers\\')) {
            return (string) str($provider)->before('\\Providers\\');
        }

        return (string) str($provider)->beforeLast('\\');
    }

    private function classFromPackagePath(string $namespace, string $packagePath, string $path): ?string
    {
        $class = $this->classNameFromPackagePath($namespace, $packagePath, $path);

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        /** @var class-string $class */
        return $class;
    }

    private function classNameFromPackagePath(string $namespace, string $packagePath, string $path): ?string
    {
        $sourcePath = realpath($packagePath . DIRECTORY_SEPARATOR . 'src');
        $realPath = realpath($path);

        if (! is_string($sourcePath) || ! is_string($realPath)) {
            return null;
        }

        $relativePath = str($realPath)
            ->after($sourcePath . DIRECTORY_SEPARATOR)
            ->replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''])
            ->toString();

        $class = $namespace . '\\' . $relativePath;

        return $class === $namespace . '\\' ? null : $class;
    }
}
