<?php

declare(strict_types=1);

use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Settings\AdminSettingsSchema;
use Capell\Admin\Filament\Settings\CoreSettingsSchema;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Filament\Settings\ThemeStudioSettingsSchema;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
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
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\DefaultPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\HomePageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\MaintenancePageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\NotFoundPageBlueprintInterceptor;
use Capell\Admin\Support\Interceptors\Blueprints\Pages\SystemPageBlueprintInterceptor;
use Capell\Admin\Support\Routing\AdminFrontendRouteReservationContributor;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\FrontendRouteReservationContributor;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Models\ModelInterceptorRegistry;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

it('registers Core before Admin when Admin is registered first', function (): void {
    $loadedProviders = new ReflectionProperty($this->app, 'loadedProviders');
    $serviceProviders = new ReflectionProperty($this->app, 'serviceProviders');
    $booted = new ReflectionProperty($this->app, 'booted');
    $originalLoadedProviders = $loadedProviders->getValue($this->app);
    $originalServiceProviders = $serviceProviders->getValue($this->app);
    $originalBooted = $booted->getValue($this->app);

    $providersWithoutCore = $originalLoadedProviders;
    $serviceProvidersWithoutCore = $originalServiceProviders;
    unset($providersWithoutCore[CapellServiceProvider::class], $serviceProvidersWithoutCore[CapellServiceProvider::class]);

    $loadedProviders->setValue($this->app, $providersWithoutCore);
    $serviceProviders->setValue($this->app, $serviceProvidersWithoutCore);
    $booted->setValue($this->app, false);

    try {
        new AdminServiceProvider($this->app)->registeringPackage();

        expect($this->app->providerIsLoaded(CapellServiceProvider::class))->toBeTrue()
            ->and(resolve(RecordsExtensionContributionReceipt::class))
            ->toBeInstanceOf(RecordsExtensionContributionReceipt::class)
            ->and(resolve(PackageSurfaceRegistrar::class))
            ->toBeInstanceOf(PackageSurfaceRegistrar::class);

        resolve(AdminRuntimeActivator::class)->prepare();

        $plugin = resolve(CapellAdminPlugin::class);
        $panel = Panel::make()->id('admin-first-provider-order');
        $plugin->register($panel);

        expect(array_count_values($panel->getPages())[SettingsPage::class] ?? 0)->toBe(1)
            ->and(array_count_values($panel->getResources())[LayoutResource::class] ?? 0)->toBe(1)
            ->and(CapellAdmin::getAdminSurfaceRegistry()->resources())
            ->toContain(ResourceEnum::Layout->value)
            ->and(CapellAdmin::getAdminSurfaceRegistry()->configuratorsForGroup('Languages'))
            ->toBe(['Default' => DefaultLanguageConfigurator::class]);
    } finally {
        $loadedProviders->setValue($this->app, $originalLoadedProviders);
        $serviceProviders->setValue($this->app, $originalServiceProviders);
        $booted->setValue($this->app, $originalBooted);
    }
});

it('registers the admin frontend route reservation contribution', function (): void {
    $contributors = collect($this->app->tagged(FrontendRouteReservationContributor::TAG));

    expect($contributors)
        ->toHaveCount(1)
        ->and($contributors->first())->toBeInstanceOf(AdminFrontendRouteReservationContributor::class)
        ->and($contributors->first())->toBe(resolve(AdminFrontendRouteReservationContributor::class));
});

it('registers the built-in overview stat contract', function (): void {
    $expected = [
        'capell_overview.pages' => ['label' => 'stat_total_pages', 'description' => 'pages', 'sort' => 10],
        'capell_overview.sites' => ['label' => 'stat_sites', 'description' => 'sites', 'sort' => 20],
        'capell_overview.languages' => ['label' => 'stat_languages', 'description' => 'languages', 'sort' => 30],
        'capell_overview.page_types' => ['label' => 'stat_page_types', 'description' => 'page_types', 'sort' => 40],
    ];
    $builtInStats = array_values(array_filter(
        CapellAdmin::getOverviewStats(false),
        static fn (CapellOverviewStatData $stat): bool => array_key_exists($stat->key, $expected),
    ));
    $stats = collect($builtInStats)->keyBy('key');
    $enabledStats = collect(CapellAdmin::getOverviewStats())->keyBy('key');

    expect(array_map(static fn (CapellOverviewStatData $stat): string => $stat->key, $builtInStats))
        ->toBe(array_keys($expected));

    foreach ($expected as $key => $arguments) {
        // Registered but demoted: the keys survive for extensions that assert on
        // them, while the dashboard shows the audience stats by default.
        expect($stats)->toHaveKey($key)
            ->and($enabledStats)->not->toHaveKey($key);
        $stat = $stats->get($key);

        if ($stat === null) {
            throw new RuntimeException(sprintf('Expected overview stat [%s] to be registered.', $key));
        }

        expect($stat->key)->toBe($key)
            ->and($stat->label)->toBe(__(sprintf('capell-admin::dashboard.%s', $arguments['label'])))
            ->and($stat->value)->toBe('0')
            ->and($stat->group)->toBe(__('capell-admin::dashboard.overview_group_core'))
            ->and($stat->description)->toBe(__(sprintf('capell-admin::dashboard.overview_stat_%s_description', $arguments['description'])))
            ->and($stat->url)->toBeNull()
            ->and($stat->color)->toBeNull()
            ->and($stat->sort)->toBe($arguments['sort']);
    }

    // Each stat now carries its own settings key, so an operator can switch a
    // single count back on instead of the whole overview group.
    expect(CapellAdmin::getDefaultEnabledOverviewStatKeys())
        ->toContain('capell_overview_visitors')
        ->toContain('capell_overview_views_per_visitor')
        ->not->toContain('capell_overview_pages')
        ->and(CapellAdmin::getOverviewStatKeys())
        ->toContain('capell_overview_pages')
        ->toContain('capell_overview_page_types')
        ->and(CapellAdmin::getOverviewStatSettings())->toContain([
            'key' => 'capell_overview_pages',
            'label' => __('capell-admin::dashboard.stat_total_pages'),
            'group' => __('capell-admin::dashboard.overview_group_core'),
            'description' => __('capell-admin::dashboard.overview_stat_pages_description'),
        ]);
});

it('registers the built-in dashboard widgets for each dashboard', function (): void {
    $adminWidgets = static fn (DashboardEnum $dashboard): array => array_values(array_filter(
        CapellAdmin::getDashboardFilamentWidgets($dashboard),
        static fn (string $widget): bool => str_starts_with($widget, 'Capell\\Admin\\'),
    ));

    expect($adminWidgets(DashboardEnum::Main))->toBe([
        AnalyticsOverviewFilamentWidget::class,
        AnalyticsTrendFilamentWidget::class,
        AnalyticsInsightsFilamentWidget::class,
        AnalyticsRecentActivityFilamentWidget::class,
        CapellAccountFilamentWidget::class,
        CapellInfoFilamentWidget::class,
        ListPagesFilamentWidget::class,
        RecentActivityFilamentWidget::class,
    ])->and($adminWidgets(DashboardEnum::MarketingStudio))->toBe([
        MarketingStudioQuickActionsFilamentWidget::class,
        MarketingStudioWorkQueueFilamentWidget::class,
        MarketingStudioLaunchReadinessFilamentWidget::class,
        MarketingStudioTimelineFilamentWidget::class,
        MarketingStudioAdvancedFilamentWidget::class,
    ])->and($adminWidgets(DashboardEnum::Extensions))->toBe([
        ExtensionStatsOverviewFilamentWidget::class,
        ExtensionHealthFilamentWidget::class,
        ExtensionActionsFilamentWidget::class,
        InstalledExtensionsFilamentWidget::class,
        ExtensionDiagnosticsFilamentWidget::class,
        ExtensionUpdateReadinessFilamentWidget::class,
        ExtensionDependencyGraphFilamentWidget::class,
        ExtensionRuntimeCompatibilityFilamentWidget::class,
        RecentlyChangedExtensionsFilamentWidget::class,
    ]);
});

it('registers a blueprint interceptor for every built-in page type', function (): void {
    $registry = resolve(ModelInterceptorRegistry::class);
    $interceptors = [
        PageTypeEnum::Default->value => DefaultPageBlueprintInterceptor::class,
        PageTypeEnum::NotFound->value => NotFoundPageBlueprintInterceptor::class,
        PageTypeEnum::Home->value => HomePageBlueprintInterceptor::class,
        PageTypeEnum::Maintenance->value => MaintenancePageBlueprintInterceptor::class,
        PageTypeEnum::System->value => SystemPageBlueprintInterceptor::class,
    ];

    foreach ($interceptors as $pageType => $interceptor) {
        $registered = $registry->getInterceptorsForModelAndKey(Blueprint::class, [
            'key' => $pageType,
            'type' => BlueprintSubjectEnum::Page,
        ]);
        $builtIns = array_values(array_intersect($registered, array_values($interceptors)));

        expect($registered)->toContain($interceptor)
            ->and($builtIns)->toBe([$interceptor]);
    }
});

it('registers the built-in settings surfaces with stable classes schemas and metadata', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    $builtInSchemas = static fn (string $group, array $expected): array => array_intersect_key(
        $registry->getSchemas($group),
        $expected,
    );

    expect($registry->getSettingsClass('core'))->toBe(CoreSettings::class)
        ->and($builtInSchemas('core', ['CoreSettingsSchema' => true]))->toBe(['CoreSettingsSchema' => CoreSettingsSchema::class])
        ->and($registry->getSettingsClass('admin'))->toBe(AdminSettings::class)
        ->and($builtInSchemas('admin', ['AdminSettingsSchema' => true, 'DashboardSettingsSchema' => true]))->toBe([
            'AdminSettingsSchema' => AdminSettingsSchema::class,
            'DashboardSettingsSchema' => DashboardSettingsSchema::class,
        ])
        ->and($registry->getSettingsClass('theme_studio'))->toBe(ThemeStudioSettings::class)
        ->and($builtInSchemas('theme_studio', ['ThemeStudioSettingsSchema' => true]))->toBe(['ThemeStudioSettingsSchema' => ThemeStudioSettingsSchema::class]);

    expect($registry->getMetadata('core'))->toMatchArray([
        'group' => 'core',
        'label' => 'capell-admin::generic.core',
        'icon' => Heroicon::OutlinedCog6Tooth,
        'navigationGroup' => 'capell-admin::navigation.group_settings',
        'navigationSort' => 90,
        'packageName' => CapellServiceProvider::$packageName,
    ])->and($registry->getMetadata('admin'))->toMatchArray([
        'group' => 'admin',
        'label' => 'capell-admin::generic.admin_settings',
        'icon' => Heroicon::OutlinedWrenchScrewdriver,
        'navigationGroup' => 'capell-admin::navigation.group_settings',
        'navigationSort' => 91,
        'packageName' => 'capell-app/admin',
    ])->and($registry->getMetadata('theme_studio'))->toMatchArray([
        'group' => 'theme_studio',
        'label' => 'capell-admin::generic.theme_studio',
        'icon' => Heroicon::OutlinedSwatch,
        'navigationGroup' => 'capell-admin::navigation.group_settings',
        'navigationSort' => 92,
        'packageName' => CapellServiceProvider::$packageName,
    ]);
});
