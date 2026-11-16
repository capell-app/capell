<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Clusters\FixtureCluster;
use Capell\Admin\Tests\Fixtures\ClusterPackage\src\Filament\Resources\Widgets\FixtureClusteredResource;
use Capell\Admin\Tests\Fixtures\ClusterPackage\src\Providers\ClusterFixtureServiceProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestLatePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestLateResource;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Filament\Facades\Filament;
use Filament\Pages\Page as FilamentPage;
use Filament\Panel;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    TestLateResource::$shouldRegisterWithPanel = true;
});

it('resolves installed package classes from psr4 file paths', function (): void {
    $plugin = CapellAdminPlugin::make();
    $packagePath = storage_path('framework/testing/admin-surface-parser/newsletter');
    $fixturesPath = $packagePath . '/src/Filament';

    File::ensureDirectoryExists($fixturesPath . '/Resources/Subscribers');
    File::ensureDirectoryExists($fixturesPath . '/Resources/Articles');
    File::ensureDirectoryExists($fixturesPath . '/Pages');
    File::put($fixturesPath . '/Resources/Subscribers/SubscriberResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Capell\Newsletter\Filament\Resources\Subscribers;

use Filament\Resources\Resource;

final class SubscriberResource extends Resource
{
    public const string SHOULD_NOT_PARSE_THIS = self::class;
}
PHP);
    File::put($fixturesPath . '/Resources/Articles/ArticleResource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Capell\Blog\Filament\Resources\Articles;

use Filament\Resources\Resource;

class ArticleResource extends Resource {}
PHP);
    File::put($fixturesPath . '/Pages/ImportSitesPage.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Filament\Pages;

use Filament\Pages\Page;

final class ImportSitesPage extends Page {}
PHP);

    $classNameFromPackagePath = Closure::bind(
        fn (string $namespace, string $packagePath, string $path): ?string => $this->classNameFromPackagePath($namespace, $packagePath, $path),
        $plugin,
        $plugin::class,
    );

    try {
        expect($classNameFromPackagePath('Capell\\Newsletter', $packagePath, $fixturesPath . '/Resources/Subscribers/SubscriberResource.php'))
            ->toBe('Capell\\Newsletter\\Filament\\Resources\\Subscribers\\SubscriberResource')
            ->and($classNameFromPackagePath('Capell\\Newsletter', $packagePath, $fixturesPath . '/Resources/Articles/ArticleResource.php'))
            ->toBe('Capell\\Newsletter\\Filament\\Resources\\Articles\\ArticleResource')
            ->and($classNameFromPackagePath('Capell\\Newsletter', $packagePath, $fixturesPath . '/Pages/ImportSitesPage.php'))
            ->toBe('Capell\\Newsletter\\Filament\\Pages\\ImportSitesPage');
    } finally {
        File::deleteDirectory(dirname($packagePath));
    }
});

it('can populate panel membership from contributions before route construction', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();

    $plugin = CapellAdminPlugin::make();
    $panel = Panel::make()->id('late-admin-surface-sync');

    $synchronizeAdminSurface = Closure::bind(
        fn (Panel $panel): CapellAdminPlugin => $this->synchronizeAdminSurface($panel),
        $plugin,
        $plugin::class,
    );

    expect($panel->getPages())->not->toContain(TestLatePage::class)
        ->and($panel->getResources())->not->toContain(TestLateResource::class);

    /*
     * This internal projection belongs to initial route construction. Public
     * runtime synchronisation defers new membership to a fresh application.
     */
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(TestLatePage::class));
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(TestLateResource::class, group: 'Late'));

    $synchronizeAdminSurface($panel);

    expect($panel->getPages())->toContain(TestLatePage::class)
        ->and($panel->getResources())->toContain(TestLateResource::class);
});

it('puts installed package clusters on the panel before its routes are built', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    CapellCore::partialMock()->shouldReceive('getInstalledPackages')->andReturn(collect([
        new PackageData(
            name: 'vendor/cluster-fixture',
            type: PackageTypeEnum::Package,
            serviceProviderClass: ClusterFixtureServiceProvider::class,
            path: __DIR__ . '/../../../Fixtures/ClusterPackage',
        ),
    ]));

    $plugin = CapellAdminPlugin::make();
    $panel = Panel::make()->id('cluster-discovery');

    // Resources inside a cluster link to the cluster's own route, so a cluster
    // contributed only after route construction leaves those links undefined.
    $registerPages = Closure::bind(
        fn (Panel $panel): CapellAdminPlugin => $this->registerPages($panel),
        $plugin,
        $plugin::class,
    );

    $registerPages($panel);

    expect($panel->getPages())->toContain(FixtureCluster::class)
        ->and(CapellAdmin::getAdminSurfaceRegistry()->pages())->toContain(FixtureCluster::class);
});

it('does not rediscover the admin package built-in resources', function (): void {
    $plugin = CapellAdminPlugin::make();
    CapellAdmin::clearAdminSurfaceContributions();

    $discoverResources = Closure::bind(
        fn (): array => $this->discoverInstalledPackageFilamentResources(),
        $plugin,
        $plugin::class,
    );

    expect($discoverResources())->not->toContain(LayoutResource::class);
});

it('removes resources that no longer register with the panel during resync', function (): void {
    $plugin = CapellAdminPlugin::make();
    $panel = Panel::make()->id('late-admin-surface-sync-remove');

    $panel->resources([TestLateResource::class]);

    expect($panel->getResources())->toContain(TestLateResource::class);

    TestLateResource::$shouldRegisterWithPanel = false;

    $synchronizeAdminSurface = Closure::bind(
        fn (Panel $panel): CapellAdminPlugin => $this->synchronizeAdminSurface($panel),
        $plugin,
        $plugin::class,
    );

    $synchronizeAdminSurface($panel);

    expect($panel->getResources())->not->toContain(TestLateResource::class);
});

it('replaces panel resources directly while Filament component caching is active', function (): void {
    $plugin = CapellAdminPlugin::make();
    $panel = new Panel;

    $panelResources = new ReflectionProperty($panel, 'resources');
    $panelResources->setValue($panel, [TestLateResource::class]);

    $hasCachedComponents = new ReflectionProperty($panel, 'hasCachedComponents');
    $hasCachedComponents->setValue($panel, true);

    $replacePanelResources = new ReflectionMethod($plugin, 'replacePanelResources');

    $replacePanelResources->invoke($plugin, $panel, [TestLateResource::class]);

    expect($panel->getResources())->toContain(TestLateResource::class);
});

it('registers contributed clustered resources with their cluster so it shows in navigation', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(FixtureCluster::class));
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(FixtureClusteredResource::class, group: 'Widgets'));

    $plugin = CapellAdminPlugin::make();
    $panel = Panel::make()->id('clustered-resource-sync');

    $synchronizeAdminSurface = Closure::bind(
        fn (Panel $panel): CapellAdminPlugin => $this->synchronizeAdminSurface($panel),
        $plugin,
        $plugin::class,
    );

    $synchronizeAdminSurface($panel);
    $synchronizeAdminSurface($panel);

    expect($panel->getResources())->toContain(FixtureClusteredResource::class)
        ->and($panel->getClusteredComponents(FixtureCluster::class))->toBe([FixtureClusteredResource::class]);

    Filament::setCurrentPanel($panel);

    expect(FixtureCluster::shouldRegisterNavigation())->toBeTrue();
});

it('drops a resource from its cluster when it stops registering with the panel', function (): void {
    $plugin = CapellAdminPlugin::make();
    $panel = Panel::make()->id('clustered-resource-removal');

    $replacePanelResources = new ReflectionMethod($plugin, 'replacePanelResources');
    $replacePanelResources->invoke($plugin, $panel, [FixtureClusteredResource::class]);

    expect($panel->getClusteredComponents(FixtureCluster::class))->toBe([FixtureClusteredResource::class]);

    $replacePanelResources->invoke($plugin, $panel, []);

    expect($panel->getClusteredComponents(FixtureCluster::class))->toBe([]);
});

it('does not silence the shared navigation flag when an extension page is a cluster', function (): void {
    $flag = new ReflectionProperty(FilamentPage::class, 'shouldRegisterNavigation');
    $original = $flag->getValue();

    try {
        $flag->setValue(null, true);

        CapellAdmin::registerExtensionPage('vendor/cluster-fixture', FixtureCluster::class);

        expect($flag->getValue())->toBeTrue();
    } finally {
        $flag->setValue(null, $original);
    }
});
