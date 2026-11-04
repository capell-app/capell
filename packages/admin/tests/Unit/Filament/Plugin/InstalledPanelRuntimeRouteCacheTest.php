<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Tests\AdminTestCase;
use Illuminate\Routing\RouteCollection;
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

    /** An admin closure registered through Core's admin route helper stays owned by the panel once cached. */
    public function test_a_cached_admin_closure_runs_the_default_panel_authentication(): void
    {
        AdminRouteProvenance::group(static function (): void {
            Route::get('/runtime-cached-admin', static fn (): string => 'admin closure served')->middleware('web');
        });
        $this->cacheRoutes();

        $this->get('/runtime-cached-admin')->assertRedirect('/admin/login');
        $this->actingAsAdmin()->get('/runtime-cached-admin')->assertOk()->assertSee('admin closure served');
    }

    /** A vendor admin route Core declares by name, such as the icon picker, stays owned once cached. */
    public function test_a_cached_declared_admin_route_runs_the_default_panel_authentication(): void
    {
        $this->cacheRoutes();

        $this->get(route('guava-icon-picker.index'))->assertRedirect('/admin/login');
    }

    /** Filament's global export download keeps sending visitors to the originating panel once cached. */
    public function test_a_cached_global_export_download_is_sent_to_the_originating_panel(): void
    {
        $this->cacheRoutes();

        $this->get(route('filament.exports.download', ['export' => 7, 'format' => 'csv']))
            ->assertRedirectContains('/admin/capell/exports/7/download');
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
