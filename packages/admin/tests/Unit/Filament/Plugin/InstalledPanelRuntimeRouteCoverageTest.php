<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationRuntimeProvider;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Override;

/** Match-time coverage of routes under the admin panel, request by request. */
final class InstalledPanelRuntimeRouteCoverageTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->panelRoute('/admin/runtime-repeat');
    }

    public function test_repeated_requests_do_not_grow_a_panel_route_middleware_stack(): void
    {
        $route = $this->panelRoute('/admin/runtime-named')->name('filament.admin.runtime-named');
        $this->actingAsAdmin();

        $this->get('/admin/runtime-named')->assertOk();
        $count = count($route->middleware());
        for ($request = 0; $request < 100; $request++) {
            $this->get('/admin/runtime-named')->assertOk();
        }

        $this->assertCount($count, $route->middleware());
    }

    public function test_a_route_that_fails_coverage_is_refused_without_closing_the_panel(): void
    {
        $this->panelRoute('/admin/runtime-excluding')->withoutMiddleware([BootAuthenticationMiddleware::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-excluding')->assertStatus(503)->assertDontSee('panel route served');
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));
        $this->get('/admin/runtime-repeat')->assertOk()->assertSee('panel route served');
        $this->get('/admin')->assertOk();
    }

    public function test_a_refused_route_cannot_exclude_the_availability_guard(): void
    {
        $this->panelRoute('/admin/runtime-unguarded')
            ->withoutMiddleware([BootAuthenticationMiddleware::class, EnsureInstalledPanelAvailable::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-unguarded')->assertStatus(503)->assertDontSee('panel route served');
        $this->get('/admin/runtime-unguarded')->assertStatus(503)->assertDontSee('panel route served');
    }

    public function test_a_route_that_excludes_only_the_availability_guard_is_refused(): void
    {
        $this->panelRoute('/admin/runtime-guardless')->withoutMiddleware([EnsureInstalledPanelAvailable::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-guardless')->assertStatus(503)->assertDontSee('panel route served');
        $this->get('/admin/runtime-repeat')->assertOk();
    }

    public function test_redefining_a_middleware_group_reconciles_the_route_again(): void
    {
        $panel = Filament::getPanel('admin');
        Route::middlewareGroup('runtime-panel-stack', [...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]);
        Route::get('/admin/runtime-grouped', static fn (): string => 'panel route served')->middleware('runtime-panel-stack');
        $this->get('/admin/runtime-grouped')->assertRedirect('/admin/login');

        // The route's declared middleware is unchanged, but what it resolves to no longer authenticates.
        Route::middlewareGroup('runtime-panel-stack', ['web']);

        $this->get('/admin/runtime-grouped')->assertRedirect('/admin/login')->assertDontSee('panel route served');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootAuthenticationRuntimeProvider::class];
    }

    /** A standalone route carrying the panel's full stack, as a package callback would. */
    private function panelRoute(string $uri): RoutingRoute
    {
        $panel = Filament::getPanel('admin');

        return Route::get($uri, static fn (): string => 'panel route served')
            ->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]);
    }
}
