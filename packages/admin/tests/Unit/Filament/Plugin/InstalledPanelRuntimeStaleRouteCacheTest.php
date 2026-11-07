<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\Diagnostics\Checks\AdminRouteOwnershipCheck;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Support\Upgrade\RequireRecordedRouteCacheStep;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\CachedActionWakeupTrap;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\CachedAssetComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SpoofedAssetPage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StaleCachePublicController;
use Capell\Core\Data\UpgradeContext;
use Closure;
use Composer\Autoload\ClassLoader;
use Filament\Auth\Pages\Login;
use Filament\Panel;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportCssModules\SupportCssModules;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use StandalonePackage\PreviewController;

/**
 * A route cache built by a release before routes recorded their ownership keeps serving the host's own
 * routes and Livewire's endpoints, refuses admin code it cannot attribute, and is reported loudly with the
 * commands that rebuild it.
 */
final class InstalledPanelRuntimeStaleRouteCacheTest extends AdminTestCase
{
    public function test_a_named_root_panel_login_from_an_old_cache_serves_guests(): void
    {
        resolve(InstalledPanelRuntime::class)->extend(Panel::make()->id('root')->path(''));
        $this->cacheRoutesAsAnEarlierRelease();

        $this->get('/admin/login')->assertOk()->assertSee('Sign in');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_login_name_cannot_exempt_an_unowned_closure(bool $cached): void
    {
        $this->renameRealLogin();
        Route::get('/admin/spoofed-login', static fn (): string => 'spoofed login served')->name('filament.admin.auth.login');
        if ($cached) {
            $this->cacheRoutesAsAnEarlierRelease();
        }

        $this->get('/admin/spoofed-login')->assertStatus(503)->assertDontSee('spoofed login served');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_login_name_cannot_exempt_an_unowned_package_controller(bool $cached): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            $this->renameRealLogin();
            Route::get('/admin/spoofed-controller-login', PreviewController::class)->name('filament.admin.auth.login');
            if ($cached) {
                $this->cacheRoutesAsAnEarlierRelease();
            }

            $this->get('/admin/spoofed-controller-login')->assertStatus(503)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    #[TestWith([0, false])]
    #[TestWith([1, false])]
    #[TestWith([2, false])]
    #[TestWith([0, true])]
    #[TestWith([1, true])]
    #[TestWith([2, true])]
    public function test_livewire_asset_paths_cannot_exempt_an_unowned_page(int $asset, bool $cached): void
    {
        $path = [EndpointResolver::componentCssPath(), EndpointResolver::componentGlobalCssPath(), EndpointResolver::componentJsPath()][$asset];
        Route::get($path, SpoofedAssetPage::class);
        if ($cached) {
            $this->cacheRoutesAsAnEarlierRelease();
        }

        $this->get(str_replace('{component}', 'spoofed-assets', $path))->assertStatus(503)->assertDontSee(SpoofedAssetPage::BODY);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_real_login_class_requires_its_own_synchronised_panel(bool $cached): void
    {
        Route::get('/admin/unknown-panel-login', Login::class)->name('filament.unknown.auth.login');
        if ($cached) {
            $this->cacheRoutesAsAnEarlierRelease();
        }

        $this->get('/admin/unknown-panel-login')->assertStatus(503);
    }

    public function test_a_serialised_action_cannot_claim_a_vendor_scope_for_different_code(): void
    {
        $action = Closure::bind(static fn (): string => 'forged vendor action', null, SupportCssModules::class);
        $this->assertInstanceOf(Closure::class, $action);
        Route::get('/runtime-forged-vendor-scope', $action);
        $this->cacheRoutesAsAnEarlierRelease();

        $this->get('/runtime-forged-vendor-scope')->assertStatus(503)->assertDontSee('forged vendor action');
    }

    public function test_cached_action_inspection_never_restores_captured_objects(): void
    {
        CachedActionWakeupTrap::$restored = false;
        $trap = new CachedActionWakeupTrap;
        Route::get('/runtime-captured-object', static fn (): string => $trap::class);
        $this->cacheRoutesAsAnEarlierRelease();

        $this->get('/runtime-captured-object')->assertStatus(503);
        $this->assertFalse(CachedActionWakeupTrap::$restored);
    }

    public function test_livewire_component_assets_from_the_live_route_table_are_served(): void
    {
        Livewire::component('runtime-cached-assets', CachedAssetComponent::class);
        $paths = [EndpointResolver::componentCssPath(), EndpointResolver::componentGlobalCssPath(), EndpointResolver::componentJsPath()];
        foreach ($paths as $path) {
            $this->get(str_replace('{component}', 'runtime-cached-assets', $path))
                ->assertOk()->assertSee(str_ends_with($path, '.js') ? 'cachedAsset' : '.cached-asset');
        }
    }

    public function test_serialised_livewire_component_assets_from_an_old_cache_are_refused(): void
    {
        Livewire::component('runtime-cached-assets', CachedAssetComponent::class);
        $paths = [EndpointResolver::componentCssPath(), EndpointResolver::componentGlobalCssPath(), EndpointResolver::componentJsPath()];
        $this->cacheRoutesAsAnEarlierRelease();
        foreach ($paths as $path) {
            $uri = str_replace('{component}', 'runtime-cached-assets', $path);
            $route = Route::getRoutes()->match(Request::create($uri));
            $this->assertTrue(RouteAction::containsSerializedClosure($route->getAction()));
            $this->assertNotInstanceOf(PanelOwnership::class, PanelOwnership::of($route));
            $this->get($uri)->assertStatus(503)->assertDontSee(str_ends_with($path, '.js') ? 'cachedAsset' : '.cached-asset');
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_livewire_update_is_public_under_a_root_panel(bool $cached): void
    {
        resolve(InstalledPanelRuntime::class)->extend(Panel::make()->id('root')->path(''));
        $uri = resolve(HandleRequests::class)->getUpdateUri();
        if ($cached) {
            $this->cacheRoutesAsAnEarlierRelease();
        }

        // Livewire returns 404 for an empty update; the panel must not refuse it with 503.
        $this->postJson($uri, ['components' => []], ['X-Livewire' => 'true'])->assertNotFound();
    }

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

    private function renameRealLogin(): void
    {
        $route = Route::getRoutes()->getByName('filament.admin.auth.login');
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        $route->setAction([...$route->getAction(), 'as' => 'runtime-original-login']);
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
