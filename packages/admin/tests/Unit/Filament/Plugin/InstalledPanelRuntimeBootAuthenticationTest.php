<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LatePanelRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateSecurityMiddleware;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Override;

/**
 * The host panel provider declares Filament authentication after registering the Capell
 * plugin, so the panel is still being configured when installed extenders first run.
 */
final class InstalledPanelRuntimeBootAuthenticationTest extends AdminTestCase
{
    public function test_panel_stays_available_when_a_package_adds_authentication_while_the_panel_boots(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(BootAuthenticationMiddleware::class, $panel->getAuthMiddleware());
        $this->assertContains(Authenticate::class, $panel->getAuthMiddleware());
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));

        $this->actingAsAdmin();
        $this->get('/admin/runtime-boot-authentication/callback')->assertOk()->assertSee('callback reached');
    }

    public function test_security_middleware_installed_after_boot_still_reaches_standalone_panel_routes(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $this->actingAsAdmin();
        $this->get('/admin/runtime-boot-authentication/callback')->assertOk();

        CapellCore::registerPackage('test/panel-runtime');
        CapellCore::forcePackageInstalled('test/panel-runtime', false);
        app()->register(LatePanelRuntimeProvider::class);
        InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));

        $route = resolve(Router::class)->getRoutes()->getByName(BootAuthenticationRuntimeProvider::ROUTE_NAME);
        $this->assertInstanceOf(Route::class, $route);
        $this->assertContains(LateSecurityMiddleware::class, resolve(Router::class)->gatherRouteMiddleware($route));
        $this->get('/admin/runtime-boot-authentication/callback')->assertRedirect('/admin/change-password');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootAuthenticationRuntimeProvider::class];
    }
}
