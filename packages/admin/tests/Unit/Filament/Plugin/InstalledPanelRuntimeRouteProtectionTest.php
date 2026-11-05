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

    public function test_routes_the_panel_registers_after_a_synchronisation_are_protected_by_the_next_one(): void
    {
        $this->synchronise();

        $late = null;
        Route::middleware(['web'])->name('filament.admin.')->group(static function () use (&$late): void {
            $late = Route::get('/late-panel-route', static fn (): string => 'ok')->name('late-panel-route');
        });
        $unrelated = Route::middleware(['web'])->get('/late-unrelated-route', static fn (): string => 'ok')->name('late.unrelated');
        $this->assertInstanceOf(IlluminateRoute::class, $late);
        $this->assertNotContains($this->guard(), $late->gatherMiddleware());

        $this->synchronise();

        $this->assertContains($this->guard(), $late->gatherMiddleware());
        $this->assertNotContains($this->guard(), $unrelated->gatherMiddleware());
        $this->get('/late-unrelated-route')->assertOk();
    }

    /** Declaring the panel's set-up after registration does not make a host route the panel's: it is refused. */
    public function test_a_route_whose_middleware_later_declares_the_panel_is_refused(): void
    {
        $route = Route::middleware(['web'])->get('/late-declared-route', static fn (): string => 'ok')->name('late.declared');
        $this->get('/late-declared-route')->assertOk();

        $route->middleware(SetUpPanel::class . ':admin');
        $this->synchronise();

        $this->assertNotContains($this->guard(), $route->gatherMiddleware());
        $this->get('/late-declared-route')->assertStatus(503)->assertDontSee('ok');
    }

    public function test_a_route_renamed_into_the_panel_is_refused(): void
    {
        $route = Route::middleware(['web'])->get('/late-renamed-route', static fn (): string => 'ok')->name('late.renamed');
        $this->synchronise();

        $route->setAction([...$route->getAction(), 'as' => 'filament.admin.renamed']);
        $this->synchronise();

        $this->get('/late-renamed-route')->assertStatus(503)->assertDontSee('ok');
    }

    public function test_an_alias_redefined_to_resolve_to_the_panel_set_up_is_refused(): void
    {
        Route::middleware(['web', 'late.panel.alias'])->get('/late-alias-route', static fn (): string => 'ok')->name('late.alias');
        resolve(Router::class)->aliasMiddleware('late.panel.alias', SubstituteBindings::class);
        $this->synchronise();
        $this->get('/late-alias-route')->assertOk();

        resolve(Router::class)->aliasMiddleware('late.panel.alias', SetUpPanel::class . ':admin');
        $this->synchronise();

        $this->get('/late-alias-route')->assertStatus(503)->assertDontSee('ok');
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
