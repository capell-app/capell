<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LatePanelRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Override;

/**
 * The host panel provider declares Filament authentication after registering the Capell
 * plugin, so the panel is still being configured when installed extenders first run.
 */
final class InstalledPanelRuntimeBootAuthenticationTest extends AdminTestCase
{
    public function test_host_authentication_declared_after_the_plugin_is_not_a_package_addition(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(BootAuthenticationMiddleware::class, $panel->getAuthMiddleware());
        $this->assertContains(Authenticate::class, $panel->getAuthMiddleware());
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));

        $this->actingAsAdmin();
        $this->get(StandaloneCallbackRouteProvider::URI)->assertOk()->assertSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_security_middleware_installed_after_boot_reaches_standalone_panel_routes(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAsAdmin();
        $this->get(StandaloneCallbackRouteProvider::URI)->assertOk();

        CapellCore::registerPackage('test/panel-runtime');
        CapellCore::forcePackageInstalled('test/panel-runtime', false);
        app()->register(LatePanelRuntimeProvider::class);
        InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));

        $this->get(StandaloneCallbackRouteProvider::URI)->assertRedirect('/admin/change-password');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootAuthenticationRuntimeProvider::class, StandaloneCallbackRouteProvider::class];
    }
}
