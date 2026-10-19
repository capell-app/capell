<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Admin\Tests\AdminTestCase;
use Filament\Facades\Filament;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

final class InstalledPanelRuntimeRouteProtectionTest extends AdminTestCase
{
    public function test_repeated_synchronisation_does_not_grow_route_middleware(): void
    {
        $route = $this->panelRoute();
        $this->synchronise();
        $before = $route->getAction('middleware');
        $resolved = resolve(Router::class)->gatherRouteMiddleware($route);

        $this->synchronise();
        $this->synchronise();

        $this->assertSame($before, $route->getAction('middleware'));
        $this->assertSame($resolved, resolve(Router::class)->gatherRouteMiddleware($route));
        $this->assertContains($this->guard(), $resolved);
    }

    public function test_routes_added_after_a_synchronisation_are_protected_by_the_next_one(): void
    {
        $this->synchronise();

        $late = Route::middleware(['web'])->get('/late-panel-route', static fn (): string => 'ok')->name('filament.admin.late-panel-route');
        $unrelated = Route::middleware(['web'])->get('/late-unrelated-route', static fn (): string => 'ok')->name('late.unrelated');
        $this->assertNotContains($this->guard(), $late->gatherMiddleware());

        $this->synchronise();

        $this->assertContains($this->guard(), $late->gatherMiddleware());
        $this->assertNotContains($this->guard(), $unrelated->gatherMiddleware());
    }

    public function test_a_route_whose_middleware_later_declares_the_panel_is_protected(): void
    {
        $route = Route::middleware(['web'])->get('/late-declared-route', static fn (): string => 'ok')->name('late.declared');
        $this->synchronise();
        $this->assertNotContains($this->guard(), $route->gatherMiddleware());

        $route->middleware(SetUpPanel::class . ':admin');
        $this->synchronise();

        $this->assertContains($this->guard(), $route->gatherMiddleware());
    }

    public function test_a_route_renamed_into_the_panel_is_protected(): void
    {
        $route = Route::middleware(['web'])->get('/late-renamed-route', static fn (): string => 'ok')->name('late.renamed');
        $this->synchronise();

        $route->setAction([...$route->getAction(), 'as' => 'filament.admin.renamed']);
        $this->synchronise();

        $this->assertContains($this->guard(), $route->gatherMiddleware());
    }

    public function test_an_alias_redefined_to_resolve_to_the_panel_set_up_is_reclassified(): void
    {
        $route = Route::middleware(['web', 'late.panel.alias'])->get('/late-alias-route', static fn (): string => 'ok')->name('late.alias');
        resolve(Router::class)->aliasMiddleware('late.panel.alias', SubstituteBindings::class);
        $this->synchronise();
        $this->assertNotContains($this->guard(), $route->gatherMiddleware());

        resolve(Router::class)->aliasMiddleware('late.panel.alias', SetUpPanel::class . ':admin');
        $this->synchronise();

        $this->assertContains($this->guard(), $route->gatherMiddleware());
    }

    private function guard(): string
    {
        return EnsureInstalledPanelAvailable::class . ':admin';
    }

    private function synchronise(): void
    {
        $panel = Filament::getPanel('admin');
        $plugin = $panel->getPlugin('capell-admin');
        $this->assertInstanceOf(CapellAdminPlugin::class, $plugin);
        $plugin->synchronizePanelAdminSurface($panel);
    }

    private function panelRoute(): IlluminateRoute
    {
        foreach (resolve(Router::class)->getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'filament.admin.')) {
                return $route;
            }
        }

        $this->fail('No admin panel route is registered.');
    }
}
