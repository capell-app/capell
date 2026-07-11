<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestLatePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestLateResource;
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

it('can resynchronize late admin-surface contributions onto the panel', function (): void {
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
     * Packages can contribute pages/resources from boot(), booted(), or
     * afterResolving() callbacks. When that happens after the initial panel
     * build, the registry is correct but Filament's panel stays stale unless
     * we project the final registry back onto the panel.
     */
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(TestLatePage::class));
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(TestLateResource::class, group: 'Late'));

    $synchronizeAdminSurface($panel);

    expect($panel->getPages())->toContain(TestLatePage::class)
        ->and($panel->getResources())->toContain(TestLateResource::class);
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
