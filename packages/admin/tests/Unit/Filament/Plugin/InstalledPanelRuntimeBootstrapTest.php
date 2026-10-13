<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootstrapRuntimePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootstrapRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimeResource;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateTopologyRuntimeProvider;
use Capell\Core\Contracts\AdminPermissionSynchronizer;
use Capell\Core\Facades\CapellCore;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Override;

final class InstalledPanelRuntimeBootstrapTest extends AdminTestCase
{
    public function test_installed_pages_are_contributed_before_fresh_panel_routes_are_constructed(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $this->assertContains(BootstrapRuntimePage::class, $panel->getPages());
        $this->assertTrue(Route::has(BootstrapRuntimePage::getRouteName($panel)));
        $this->assertNotEmpty(BootstrapRuntimePage::getNavigationItems());
        $this->actingAsAdmin();
        $this->get(BootstrapRuntimePage::getUrl(panel: 'admin'))->assertOk();
    }

    public function test_fresh_application_applies_the_deferred_panel_extender_topology(): void
    {
        $this->assertContains(LateRuntimeResource::class, Filament::getPanel('admin')->getResources());
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->requiresFreshApplication('admin'));
        $this->assertTrue(resolve(AdminPermissionSynchronizer::class)->hasBootedPanel());
        $this->get('/admin/login')->assertOk();
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);
        CapellCore::registerPackage('test/panel-topology');
        CapellCore::forcePackageInstalled('test/panel-topology');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootstrapRuntimeProvider::class, LateTopologyRuntimeProvider::class];
    }
}
