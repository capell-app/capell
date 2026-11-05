<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\Diagnostics\Checks\AdminRouteOwnershipCheck;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Support\Upgrade\RequireRecordedRouteCacheStep;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StaleCachePublicController;
use Capell\Core\Data\UpgradeContext;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * A route cache built by a release before routes recorded their ownership keeps serving the host's own
 * routes and Livewire's endpoints, refuses admin code it cannot attribute, and is reported loudly with the
 * commands that rebuild it.
 */
final class InstalledPanelRuntimeStaleRouteCacheTest extends AdminTestCase
{
    public function test_a_stale_route_cache_keeps_serving_host_routes_and_refuses_unattributable_admin_code(): void
    {
        Route::get('/runtime-stale-public', StaleCachePublicController::class)->middleware('web');
        Route::get('/runtime-stale-closure', static fn (): string => 'stale closure')->middleware('web');
        Route::get('/runtime-stale-admin/{initials}.svg', AdminAvatarController::class)->middleware('web');
        $log = Log::spy();
        $this->cacheRoutesAsAnEarlierRelease();

        $this->get('/runtime-stale-public')->assertOk()->assertSee(StaleCachePublicController::BODY);
        $this->get('/runtime-stale-closure')->assertStatus(503);
        $this->get('/runtime-stale-admin/AB.svg')->assertStatus(503);
        $log->shouldHaveReceived('critical')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'php artisan route:clear && php artisan route:cache'));
    }

    public function test_the_doctor_and_the_upgrade_report_a_stale_route_cache_with_the_rebuild_commands(): void
    {
        $this->cacheRoutesAsAnEarlierRelease();
        $this->app->instance('routes.cached', true);

        $result = resolve(AdminRouteOwnershipCheck::class)->check();
        $this->assertFalse($result->passed);
        $this->assertStringContainsString('php artisan route:clear && php artisan route:cache', (string) $result->remediation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php artisan route:clear && php artisan route:cache');
        resolve(RequireRecordedRouteCacheStep::class)->run(new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []));
    }

    public function test_a_recorded_route_cache_passes_the_doctor_and_the_upgrade(): void
    {
        $this->assertTrue(resolve(AdminRouteOwnershipCheck::class)->check()->passed);
        $this->assertTrue(resolve(RequireRecordedRouteCacheStep::class)->run(new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: [])));
        $this->assertSame([], resolve(InstalledPanelRuntime::class)->unrecordedRoutes());
    }

    /** Write and load a route cache whose routes carry no ownership record, as an earlier release built it. */
    private function cacheRoutesAsAnEarlierRelease(): void
    {
        $routes = Route::getRoutes();
        $this->assertInstanceOf(RouteCollection::class, $routes);
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $compiled = $routes->compile();
        foreach (array_keys($compiled['attributes']) as $name) {
            unset($compiled['attributes'][$name]['action'][PanelOwnership::ACTION]);
        }

        $file = storage_path('framework/testing-stale-routes-' . bin2hex(random_bytes(6)) . '.php');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '<?php return ' . var_export($compiled, true) . ';');
        try {
            Route::setCompiledRoutes(require $file);
        } finally {
            File::delete($file);
        }
    }
}
