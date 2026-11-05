<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Support\TableName;
use Closure;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Route ownership is recorded when a route is registered and travels with it through `route:cache`,
 * so a cached route is held to exactly the panel security it was registered with.
 */
final class InstalledPanelRuntimeRouteCacheTest extends AdminTestCase
{
    /** A host's own public closure, such as a feed, keeps serving guests once routes are cached. */
    public function test_a_cached_public_host_closure_serves_guests(): void
    {
        require_once dirname(__DIR__, 3) . '/Fixtures/Filament/Plugin/scopeless-closure.php';
        Route::get('/whats-new/feed.xml', capell_runtime_scopeless_closure())->middleware('web');
        $this->cacheRoutes();

        $this->get('/whats-new/feed.xml')->assertOk()->assertSee('cached closure served');
    }

    /** A cached closure's captured values never decide who owns it, even one that names an admin class. */
    public function test_a_cached_host_closure_capturing_an_admin_class_name_serves_guests(): void
    {
        $captured = ['scope' => AdminAvatarController::class];
        Route::get('/whats-new/scoped.xml', static fn (): string => 'captured ' . count($captured))->middleware('web');
        $this->cacheRoutes();

        $this->get('/whats-new/scoped.xml')->assertOk()->assertSee('captured 1');
    }

    /** An admin closure registered for the admin panel stays that panel's once cached. */
    public function test_a_cached_admin_closure_runs_the_admin_panel_authentication(): void
    {
        AdminRouteProvenance::panel(AdminPanelEntrypoint::PANEL, static function (): void {
            Route::get('/runtime-cached-admin', static fn (): string => 'admin closure served')->middleware('web');
        });
        $this->cacheRoutes();

        $this->get('/runtime-cached-admin')->assertRedirect('/admin/login');
        $this->actingAsAdmin()->get('/runtime-cached-admin')->assertOk()->assertSee('admin closure served');
    }

    /** An admin plugin endpoint serving the admin panel's forms, such as the icon picker, stays the panel's once cached. */
    public function test_a_cached_declared_admin_route_runs_the_default_panel_authentication(): void
    {
        $this->cacheRoutes();

        $this->get(route('guava-icon-picker.index'))->assertRedirect('/admin/login');
    }

    /** Admin code registered without the helper, even after boot, is recorded as admin code when it is registered, so it stays refused once cached. */
    public function test_cached_unmarked_admin_routes_stay_refused(): void
    {
        Route::get('/runtime-unmarked-admin/{initials}.svg', AdminAvatarController::class)->middleware('web');
        Route::get('/runtime-unmarked-closure/{initials}.svg', Closure::fromCallable(resolve(AdminAvatarController::class)))->middleware('web');
        $this->cacheRoutes();

        $this->get('/runtime-unmarked-admin/AB.svg')->assertStatus(503);
        $this->get('/runtime-unmarked-closure/AB.svg')->assertStatus(503);
    }

    /** Filament's global export download keeps sending visitors to the originating panel once cached. */
    public function test_a_cached_global_export_download_is_sent_to_the_originating_panel(): void
    {
        DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'export', 'transfer_key' => '7', 'panel' => 'admin', 'host' => 'localhost']);
        $this->cacheRoutes();

        $this->get(route('filament.exports.download', ['export' => 7, 'format' => 'csv']))
            ->assertRedirectContains('//localhost/admin/capell/exports/7/download');
    }

    /** Write and load the route cache exactly as `route:cache` does. */
    private function cacheRoutes(): void
    {
        $routes = Route::getRoutes();
        $this->assertInstanceOf(RouteCollection::class, $routes);
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $file = storage_path('framework/testing-routes-' . bin2hex(random_bytes(6)) . '.php');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '<?php return ' . var_export($routes->compile(), true) . ';');
        try {
            Route::setCompiledRoutes(require $file);
        } finally {
            File::delete($file);
        }
    }
}
