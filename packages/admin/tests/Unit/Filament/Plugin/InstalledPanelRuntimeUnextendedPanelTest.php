<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ForeignPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\InspectedRouteController;
use Filament\Http\Controllers\RedirectToHomeController;
use Illuminate\Support\Facades\Route;
use Override;
use Symfony\Component\HttpFoundation\Response;

/**
 * A panel registered without the Capell plugin keeps serving its own routes under its own stack, but cannot
 * serve Capell's admin code, which depends on the package security only the Capell plugin adds.
 */
final class InstalledPanelRuntimeUnextendedPanelTest extends AdminTestCase
{
    public function test_a_panel_without_the_capell_plugin_refuses_capell_admin_code(): void
    {
        $this->portalRoute('/portal/avatar/{initials}', AdminAvatarController::class);

        $this->get('/portal/avatar/AB')->assertStatus(503)->assertDontSee('<svg', false);
    }

    public function test_a_panel_without_the_capell_plugin_refuses_capell_resource_pages(): void
    {
        // As a host panel that discovers Capell's resources without the plugin would register them.
        $this->portalRoute('/portal/pages', ListPages::class);

        $this->get('/portal/pages')->assertStatus(503);
    }

    public function test_a_panel_without_the_capell_plugin_keeps_serving_its_own_routes(): void
    {
        $this->portalRoute('/portal/avatar/{initials}', AdminAvatarController::class);
        $this->portalRoute('/portal/inspected', InspectedRouteController::class);

        $this->get('/portal/inspected')->assertOk()->assertSee('inspected route served');
    }

    public function test_a_panel_without_the_capell_plugin_keeps_serving_filaments_own_routes(): void
    {
        $this->portalRoute('/portal/home', RedirectToHomeController::class);

        // Not refused: Filament answers under the panel's own stack.
        $this->assertNotSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->get('/portal/home')->baseResponse->getStatusCode());
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [ForeignPanelProvider::class]);

        return $providers;
    }

    /** @param class-string $controller */
    private function portalRoute(string $uri, string $controller): void
    {
        $route = null;
        Route::name('filament.portal.')->middleware('panel:portal')->group(static function () use (&$route, $uri, $controller): void {
            $route = Route::get($uri, $controller);
        });
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        $this->assertSame('portal', PanelOwnership::of($route)?->panel);
    }
}
