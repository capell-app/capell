<?php

declare(strict_types=1);

use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Support\AdminPanelIntegration\AdminPanelProviderEditor;
use Capell\Admin\Tests\Support\AdminPanelProviderFixtures;

it('adds capell panel integration to a clean provider', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, AdminPanelProviderFixtures::clean());

    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addColors()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addPlugin([['in' => 'Filament/Configurators', 'for' => 'App\\Filament\\Configurators']])->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addSitePermissionScopeMiddleware()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addDashboardPage()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addWidgets()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addNavigation()->status)->toBe(AdminPanelChangeStatus::Applied);

    $editor->save();
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('use Capell\\Admin\\Enums\\FilamentColorEnum;')
        ->and($contents)->toContain('use Capell\\Admin\\Facades\\CapellAdmin;')
        ->and($contents)->toContain('use Capell\\Admin\\Filament\\Pages\\CapellDashboard;')
        ->and($contents)->toContain('use Capell\\Admin\\Filament\\Plugin\\CapellAdminPlugin;')
        ->and($contents)->toContain('use Capell\\Admin\\Http\\Middleware\\SetSitePermissionScope;')
        ->and($contents)->toContain('use Filament\\Http\\Middleware\\Authenticate;')
        ->and($contents)->toContain('->colors(FilamentColorEnum::colors())')
        ->and($contents)->toContain('->pages([CapellDashboard::class])')
        ->and($contents)->toContain('->authMiddleware([Authenticate::class, SetSitePermissionScope::class])')
        ->and($contents)->toContain('->navigationItems(CapellAdmin::getNavigationItems())')
        ->and($contents)->toContain('->navigationGroups(CapellAdmin::getNavigationGroups())')
        ->and($contents)->toContain("->plugin(CapellAdminPlugin::make()\n                ->discoverConfigurators(in: app_path('Filament/Configurators')")
        ->and($contents)->not->toContain('FilamentTourPlugin')
        ->and($contents)->not->toContain('->login()->colors')
        ->and($contents)->not->toContain('->colors(FilamentColorEnum::colors())->plugin')
        ->and($contents)->not->toContain('CapellAdminPlugin::make()->discoverConfigurators')
        ->and($contents)->toContain('...CapellAdmin::getWidgets()');
});

it('requires manual navigation when existing navigation items are customised', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->navigationItems([
                NavigationItem::make('DashboardReports'),
            ])
            ->login();
    }
}
PHP);

    $editor = new AdminPanelProviderEditor($path);

    $result = $editor->addNavigation();
    $editor->save();
    $contents = (string) file_get_contents($path);

    expect($result->status)->toBe(AdminPanelChangeStatus::Manual)
        ->and($contents)->not->toContain('CapellAdmin::getNavigationItems()')
        ->and($contents)->not->toContain('CapellAdmin::getNavigationGroups()');
});

it('adds site permission scope to an existing auth middleware array', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authMiddleware([
                Authenticate::class,
            ])
            ->login();
    }
}
PHP);

    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addSitePermissionScopeMiddleware()->status)->toBe(AdminPanelChangeStatus::Applied);

    $editor->save();
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('use Capell\\Admin\\Http\\Middleware\\SetSitePermissionScope;')
        ->and($contents)->toContain('Authenticate::class,')
        ->and($contents)->toContain('SetSitePermissionScope::class');
});

it('reports already-applied integration changes without mutating the provider twice', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, AdminPanelProviderFixtures::clean());

    $editor = new AdminPanelProviderEditor($path);

    $editor->addColors();
    $editor->addPlugin([]);
    $editor->addSitePermissionScopeMiddleware();
    $editor->addDashboardPage();
    $editor->addWidgets();
    $editor->addNavigation();
    $editor->save();

    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addColors()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addPlugin([])->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addSitePermissionScopeMiddleware()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addDashboardPage()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addWidgets()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addNavigation()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied);

    $contents = $editor->preview();

    expect(substr_count($contents, 'CapellAdminPlugin::make()'))->toBe(1)
        ->and(substr_count($contents, 'SetSitePermissionScope::class'))->toBe(1)
        ->and($contents)->toContain('discoverConfigurators')
        ->and($contents)->toContain('Filament/Configurators')
        ->and($contents)->toContain('App\\Filament\\Configurators');
});

it('merges widgets and replaces the default filament dashboard page in existing arrays', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\StatsWidget;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                StatsWidget::class,
            ])
            ->login();
    }
}
PHP);

    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addDashboardPage()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addWidgets()->status)->toBe(AdminPanelChangeStatus::Applied);

    $contents = $editor->preview();

    expect($contents)->toContain('use Capell\\Admin\\Filament\\Pages\\CapellDashboard;')
        ->and($contents)->toContain('use Capell\\Admin\\Facades\\CapellAdmin;')
        ->and($contents)->toContain('CapellDashboard::class')
        ->and($contents)->toContain('StatsWidget::class')
        ->and($contents)->toContain('...CapellAdmin::getWidgets()')
        ->and($contents)->not->toContain('                Dashboard::class');
});

it('requires manual changes for unsupported panel provider shapes', function (string $contents, string $method): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    file_put_contents($path, $contents);

    $editor = new AdminPanelProviderEditor($path);

    $result = $editor->{$method}();

    expect($result->status)->toBe(AdminPanelChangeStatus::Manual)
        ->and($result->docUrl)->toBe('https://capellcms.com/docs/admin-setup');
})->with([
    'missing class' => [
        <<<'PHP'
<?php

declare(strict_types=1);

return [];
PHP,
        'addColors',
    ],
    'panel method with setup statement' => [
        <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel->default();

        return $panel
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP,
        'addWidgets',
    ],
]);
