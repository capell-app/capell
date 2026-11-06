<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\InspectedRouteController;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SpoofedAssetPage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StaleCachePublicController;
use Capell\Tests\Fixtures\ApplicationPageComponent;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelRegistry;
use Guava\IconPicker\Http\Controllers\IconIndexController;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;

final class InstalledPanelRuntimeRegressionTest extends AdminTestCase
{
    #[TestWith([false, false])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    public function test_application_livewire_page_routes_keep_serving(bool $cached, bool $alias): void
    {
        Livewire::component('application-settings', ApplicationPageComponent::class);
        Route::livewire('/settings/application', $alias ? 'application-settings' : ApplicationPageComponent::class)->middleware('web');
        if ($cached) {
            $this->loadLegacyCache();
        }

        $this->get('/settings/application')->assertOk()->assertSee('application Livewire page served');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_unowned_admin_livewire_page_routes_are_refused(bool $cached): void
    {
        Route::livewire('/settings/unowned-admin', SpoofedAssetPage::class)->middleware('web');
        if ($cached) {
            $this->loadLegacyCache();
        }

        $this->get('/settings/unowned-admin')->assertStatus(503)->assertDontSee(SpoofedAssetPage::BODY);
    }

    public function test_explicit_routes_wait_for_their_registered_panel_to_be_synchronised(): void
    {
        $panel = Panel::make()->id('external')->path('external')->middleware(['web'])->authMiddleware([PackageSecurityDenyMiddleware::class]);
        resolve(PanelRegistry::class)->register($panel);
        $this->assertArrayHasKey('external', Filament::getPanels());
        AdminRouteProvenance::panel('external', static fn () => Route::get('/external/declared', StaleCachePublicController::class));

        $this->get('/external/declared')->assertStatus(503)->assertDontSee(StaleCachePublicController::BODY);
        resolve(InstalledPanelRuntime::class)->extend($panel);
        $this->get('/external/declared')->assertForbidden()->assertSee(PackageSecurityDenyMiddleware::BODY);
    }

    #[TestWith(['/admin/extension-asset'])]
    #[TestWith(['/admin/preview/page/1'])]
    #[TestWith(['/admin/marketplace/connection/callback'])]
    public function test_first_party_entrypoints_require_a_synchronised_admin_panel(string $uri): void
    {
        // The panel is registered with Filament, but this runtime has not synchronised it.
        $runtime = new InstalledPanelRuntime(resolve(Router::class));
        $this->app->instance(InstalledPanelRuntime::class, $runtime);
        $runtime->extend(Panel::make()->id('another')->path('another'));

        $this->get($uri)->assertStatus(503);
        $this->assertFalse($runtime->isUnavailable('admin'));
    }

    #[TestWith(['/admin/oauth/callback', 'OAuth callback served'])]
    #[TestWith(['/admin/custom-sign-in', StaleCachePublicController::BODY])]
    public function test_panel_unauthenticated_callbacks_and_custom_sign_in_stay_public(string $uri, string $body): void
    {
        $panel = Filament::getPanel('admin');
        $panel->routes(static function (): void {
            Route::get('/oauth/callback', static fn (): string => 'OAuth callback served');
            Route::get('/custom-sign-in', StaleCachePublicController::class);
            Route::get('/explicit-auth', StaleCachePublicController::class)->middleware(Authenticate::class);
        });
        Route::prefix('admin')->name('filament.admin.')->middleware($panel->getMiddleware())->group(static function () use ($panel): void {
            foreach ($panel->getRoutes() as $register) {
                $register($panel);
            }
        });

        $this->get($uri)->assertOk()->assertSee($body);
        $this->get('/admin/explicit-auth')->assertRedirect('/admin/login');
    }

    public function test_unchanged_route_tables_are_not_reclassified_and_changes_invalidate_the_memo(): void
    {
        InspectedRouteController::$broken = false;
        $route = Route::get('/inspection', InspectedRouteController::class);
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Filament::getPanel('admin');
        $runtime->extend($panel);
        InspectedRouteController::$inspections = 0;

        $runtime->extend($panel);
        $runtime->extend($panel);
        $this->assertSame(0, InspectedRouteController::$inspections);

        $route->middleware('panel:admin');
        $runtime->extend($panel);
        $this->assertGreaterThan(0, InspectedRouteController::$inspections);
        $this->assertTrue($runtime->isRouteRefused($route));
        $this->assertFalse($runtime->isUnavailable('admin'));
    }

    #[TestWith(['alias'])]
    #[TestWith(['group'])]
    #[TestWith(['addition'])]
    #[TestWith(['replacement'])]
    #[TestWith(['cache-mtime'])]
    #[TestWith(['compiled'])]
    public function test_route_table_dependencies_invalidate_classification(string $change): void
    {
        InspectedRouteController::$broken = false;
        Route::get('/inspection-dependency', InspectedRouteController::class);
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Filament::getPanel('admin');
        $cache = $this->app->getCachedRoutesPath();
        $this->assertFileDoesNotExist($cache);
        if ($change === 'cache-mtime') {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache, '<?php return [];');
        }

        try {
            $runtime->extend($panel);
            InspectedRouteController::$inspections = 0;
            switch ($change) {
                case 'alias':
                    Route::aliasMiddleware('inspection.alias', Authenticate::class);
                    break;
                case 'group':
                    Route::middlewareGroup('inspection.group', [Authenticate::class]);
                    break;
                case 'addition':
                    Route::get('/inspection-added', StaleCachePublicController::class);
                    break;
                case 'replacement':
                    $routes = new RouteCollection;
                    foreach (Route::getRoutes()->getRoutes() as $route) {
                        $routes->add(clone $route);
                    }

                    Route::setRoutes($routes);
                    break;
                case 'compiled':
                    $routes = Route::getRoutes();
                    $this->assertInstanceOf(RouteCollection::class, $routes);
                    foreach ($routes->getRoutes() as $route) {
                        $route->prepareForSerialization();
                    }

                    Route::setCompiledRoutes($routes->compile());
                    break;
                case 'cache-mtime':
                    touch($cache, Date::now()->getTimestamp() + 10);
                    break;
            }

            $runtime->extend($panel);
            $this->assertGreaterThan(0, InspectedRouteController::$inspections);
            InspectedRouteController::$inspections = 0;
            $runtime->extend($panel);
            $this->assertSame(0, InspectedRouteController::$inspections);
        } finally {
            if ($change === 'cache-mtime') {
                File::delete($cache);
            }
        }
    }

    public function test_an_uninspectable_route_is_refused_without_closing_other_routes(): void
    {
        InspectedRouteController::$broken = false;
        $route = Route::get('/broken-inspection', InspectedRouteController::class);
        $log = Log::spy();
        InspectedRouteController::$broken = true;
        $runtime = resolve(InstalledPanelRuntime::class);
        try {
            $runtime->extend(Filament::getPanel('admin'));
            $this->assertTrue($runtime->isRouteRefused($route));
            $this->assertFalse($runtime->isUnavailable('admin'));
            $this->get('/broken-inspection')->assertStatus(503)->assertDontSee('inspected route served');
            $this->get('/admin/login')->assertOk();
            InspectedRouteController::$broken = false;
            $this->get('/broken-inspection')->assertOk()->assertSee('inspected route served');
            $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['reason'] ?? null) === 'Cannot inspect this route controller');
        } finally {
            InspectedRouteController::$broken = false;
        }
    }

    public function test_icon_picker_uses_the_panel_that_registered_its_route(): void
    {
        $route = null;
        Route::name('filament.portal.')->middleware('panel:portal')->group(static function () use (&$route): void {
            $route = Route::get('/portal/icons', IconIndexController::class);
        });
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        $this->assertSame('portal', PanelOwnership::of($route)?->panel);
    }

    private function loadLegacyCache(): void
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

        Route::setCompiledRoutes($compiled);
    }
}
