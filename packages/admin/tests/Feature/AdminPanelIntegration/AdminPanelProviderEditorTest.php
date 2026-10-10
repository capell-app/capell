<?php

declare(strict_types=1);

use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Support\AdminPanelIntegration\AdminPanelProviderEditor;
use Capell\Admin\Tests\Support\AdminPanelProviderFixtures;
use Capell\Tests\Support\GeneratedPanelProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\AccountWidget;
use Livewire\Livewire;

beforeEach(function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-panel-');
    throw_if($path === false, RuntimeException::class, 'Unable to create panel provider fixture.');

    $this->panelProviderPath = $path;
});

afterEach(function (): void {
    unlink($this->panelProviderPath);
});

it('persists site permission scope middleware for Livewire requests', function (): void {
    expect(Livewire::getPersistentMiddleware())->toContain(SetSitePermissionScope::class);
});

it('adds capell panel integration to a clean provider', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, AdminPanelProviderFixtures::clean());

    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addColors()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addPlugin([['in' => 'Filament/Configurators', 'for' => 'App\\Filament\\Configurators']])->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addSitePermissionScopeMiddleware()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addDashboardPage()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addWidgets()->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($editor->addNavigation()->status)->toBe(AdminPanelChangeStatus::Applied);

    $editor->save();
    $panel = GeneratedPanelProvider::load($path);

    expect($panel->getColors())->toBe(FilamentColorEnum::colors())
        ->and($panel->hasPlugin('capell-admin'))->toBeTrue()
        ->and($panel->getPages())->toContain(CapellDashboard::class)
        ->and($panel->getAuthMiddleware())->toContain(Authenticate::class, SetSitePermissionScope::class)
        ->and($panel->getWidgets())->toEqualCanonicalizing(CapellAdmin::getWidgets());
    expect(array_map(static fn (NavigationItem $item): string => $item->getLabel(), $panel->getNavigationItems()))
        ->toBe(array_map(static fn (NavigationItem $item): string => $item->getLabel(), CapellAdmin::getNavigationItems()));
    $groupLabel = static function (NavigationGroup|string|int $group): string {
        if (! $group instanceof NavigationGroup) {
            return (string) $group;
        }

        $label = $group->getLabel();
        throw_if($label === null, RuntimeException::class, 'Configured navigation groups must have labels.');

        return $label;
    };
    expect(array_map($groupLabel, $panel->getNavigationGroups()))
        ->toBe(array_map($groupLabel, CapellAdmin::getNavigationGroups()));
});

it('adds the Capell plugin when its import is unused and another plugin is registered', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Tests\Support\FixturePanelPlugin as OtherPlugin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->plugin(OtherPlugin::make());
    }
}
PHP);

    $editor = new AdminPanelProviderEditor($path);
    $result = $editor->addPlugin([]);
    $editor->save();
    $panel = GeneratedPanelProvider::load($path);

    expect($result->status)->toBe(AdminPanelChangeStatus::Applied)
        ->and($panel->hasPlugin('fixture-plugin'))->toBeTrue()
        ->and($panel->hasPlugin('capell-admin'))->toBeTrue();
});

it('requires manual navigation when existing navigation items are customised', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
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
    $panel = GeneratedPanelProvider::load($path);

    expect($result->status)->toBe(AdminPanelChangeStatus::Manual)
        ->and(array_map(static fn (NavigationItem $item): string => $item->getLabel(), $panel->getNavigationItems()))
        ->toBe(['DashboardReports'])
        ->and($panel->getNavigationGroups())->toBe([]);
});

it('adds site permission scope to an existing auth middleware array', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
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
    expect(GeneratedPanelProvider::load($path)->getAuthMiddleware())
        ->toContain(Authenticate::class, SetSitePermissionScope::class);
});

it('reports already-applied integration changes without mutating the provider twice', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, AdminPanelProviderFixtures::clean());

    $editor = new AdminPanelProviderEditor($path);

    $editor->addColors();
    $editor->addPlugin([]);
    $editor->addSitePermissionScopeMiddleware();
    $editor->addDashboardPage();
    $editor->addWidgets();
    $editor->addNavigation();
    $editor->save();

    $before = GeneratedPanelProvider::load($path);
    $editor = new AdminPanelProviderEditor($path);

    expect($editor->addColors()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addPlugin([])->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addSitePermissionScopeMiddleware()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addDashboardPage()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addWidgets()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied)
        ->and($editor->addNavigation()->status)->toBe(AdminPanelChangeStatus::AlreadyApplied);

    $editor->save();
    $after = GeneratedPanelProvider::load($path);

    expect($after->getColors())->toBe($before->getColors())
        ->and($after->getAuthMiddleware())->toBe($before->getAuthMiddleware())
        ->and($after->getPages())->toBe($before->getPages())
        ->and($after->getWidgets())->toBe($before->getWidgets())
        ->and(array_keys($after->getPlugins()))->toBe(array_keys($before->getPlugins()));
});

it('merges widgets and replaces the default filament dashboard page in existing arrays', function (): void {
    $path = $this->panelProviderPath;
    file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Widgets\AccountWidget as StatsWidget;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
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

    $editor->save();
    $panel = GeneratedPanelProvider::load($path);

    expect($panel->getPages())->toBe([CapellDashboard::class])
        ->and($panel->getWidgets())->toEqualCanonicalizing([
            AccountWidget::class,
            ...CapellAdmin::getWidgets(),
        ]);
});

it('requires manual changes for unsupported panel provider shapes', function (string $contents, string $method): void {
    $path = $this->panelProviderPath;
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
    #[\Override]
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
