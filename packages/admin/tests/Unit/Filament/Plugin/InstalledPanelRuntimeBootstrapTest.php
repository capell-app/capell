<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootstrapRuntimePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootstrapRuntimeProvider;
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

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootstrapRuntimeProvider::class];
    }
}
