<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\Diagnostics\Checks\AdminRouteOwnershipCheck;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\InspectedRouteController;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeSignInPage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SpoofedAssetPage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StaleCachePublicController;
use Capell\Tests\Fixtures\ApplicationPageComponent;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelRegistry;
use Guava\IconPicker\Http\Controllers\IconIndexController;
use Illuminate\Http\Request;
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

    #[TestWith(['routes', SpoofedAssetPage::class])]
    #[TestWith(['name', SpoofedAssetPage::class])]
    #[TestWith(['middleware', SpoofedAssetPage::class])]
    #[TestWith(['routes', AdminAvatarController::class])]
    public function test_panel_owned_admin_actions_require_authentication(string $registration, string $action): void
    {
        $panel = Filament::getPanel('admin');
        $register = static fn () => Route::get('/runtime-admin/{initials}', $action);
        if ($registration === 'routes') {
            $panel->routes($register);
            Route::prefix('admin')->name('filament.admin.')->middleware($panel->getMiddleware())->group(static function () use ($panel): void {
                foreach ($panel->getRoutes() as $routes) {
                    $routes($panel);
                }
            });
        } elseif ($registration === 'name') {
            Route::prefix('admin')->name('filament.admin.')->middleware('web')->group($register);
        } else {
            Route::prefix('admin')->middleware(['web', 'panel:admin'])->group($register);
        }

        $this->get('/admin/runtime-admin/AB')->assertRedirect('/admin/login')->assertDontSee(SpoofedAssetPage::BODY);
    }

    public function test_a_sign_in_subclass_in_the_unauthenticated_group_stays_public(): void
    {
        Route::name('filament.admin.')->middleware(['web', 'panel:admin'])->group(static fn () => Route::get('/admin/runtime-sign-in', RuntimeSignInPage::class));

        $this->get('/admin/runtime-sign-in')->assertOk()->assertSee(RuntimeSignInPage::BODY);
    }

    public function test_a_callback_controller_in_the_unauthenticated_group_stays_public(): void
    {
        Route::name('filament.admin.')->middleware(['web', 'panel:admin'])->group(static fn () => Route::get('/admin/runtime-oauth', StaleCachePublicController::class));

        $this->get('/admin/runtime-oauth')->assertOk()->assertSee(StaleCachePublicController::BODY);
    }

    public function test_excluded_custom_authentication_is_retained_before_panel_synchronisation(): void
    {
        $panel = Panel::make()->id('custom-auth')->path('custom-auth')->middleware(['web'])->authMiddleware([PackageSecurityDenyMiddleware::class]);
        resolve(PanelRegistry::class)->register($panel);
        $panel->authenticatedRoutes(static fn () => Route::get('/callback', StaleCachePublicController::class)->withoutMiddleware(PackageSecurityDenyMiddleware::class));
        Route::prefix($panel->getPath())->name('filament.custom-auth.')->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])->group(static function () use ($panel): void {
            foreach ($panel->getAuthenticatedRoutes() as $routes) {
                $routes($panel);
            }
        });

        $registered = Route::getRoutes()->match(Request::create('/custom-auth/callback'));
        $this->assertTrue($registered->getAction('capell_panel_authenticated'));
        $runtime = resolve(InstalledPanelRuntime::class);
        $log = Log::spy();
        $runtime->extend($panel);
        $this->get('/custom-auth/callback')->assertStatus(503)->assertDontSee(StaleCachePublicController::BODY);
        $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => ($context['reason'] ?? null) === __('capell-admin::message.extension_panel_security_excluded'));
    }

    public function test_icon_picker_waits_for_the_admin_panel_to_be_synchronised(): void
    {
        $runtime = new InstalledPanelRuntime(resolve(Router::class));
        $this->app->instance(InstalledPanelRuntime::class, $runtime);

        Route::get('/runtime-unsynchronised-icons', IconIndexController::class)->middleware('web');
        $this->get('/runtime-unsynchronised-icons')->assertStatus(503);
        $runtime->extend(Filament::getPanel('admin'));
        $this->get('/runtime-unsynchronised-icons')->assertRedirect('/admin/login');
    }

    #[TestWith([SpoofedAssetPage::class])]
    #[TestWith([AdminAvatarController::class])]
    public function test_host_declarations_do_not_exempt_admin_actions(string $action): void
    {
        AdminRouteProvenance::host(static fn () => Route::get('/runtime-host-admin/{initials}', $action)->middleware('web'));

        $this->get('/runtime-host-admin/AB')->assertStatus(503)->assertDontSee(SpoofedAssetPage::BODY);
    }

    public function test_a_host_declared_livewire_admin_component_is_refused_after_registration_finishes(): void
    {
        AdminRouteProvenance::host(static fn () => Route::livewire('/runtime-host-admin-component', SpoofedAssetPage::class)->middleware('web'));

        $this->get('/runtime-host-admin-component')->assertStatus(503)->assertDontSee(SpoofedAssetPage::BODY);
    }

    public function test_the_ownership_doctor_reports_uninspectable_routes_and_continues(): void
    {
        InspectedRouteController::$broken = false;
        Route::get('/runtime-doctor-inspection', InspectedRouteController::class)->name('runtime.doctor.inspection');
        AdminRouteProvenance::host(static fn () => Route::get('/runtime-doctor-admin/{initials}', AdminAvatarController::class)->name('runtime.doctor.admin'));
        InspectedRouteController::$broken = true;
        try {
            $result = resolve(AdminRouteOwnershipCheck::class)->check();
            $this->assertFalse($result->passed);
            $this->assertStringContainsString('runtime.doctor.inspection', $result->message);
            $this->assertStringContainsString('Cannot inspect this route controller', $result->message);
            $this->assertStringContainsString('runtime.doctor.admin', $result->message);
        } finally {
            InspectedRouteController::$broken = false;
        }
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
