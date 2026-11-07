<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Filament\Facades\Filament;
use Override;

/** Middleware added to a panel after its last synchronisation is enforced when a route matches. */
final class InstalledPanelRuntimeLateGrowthTest extends AdminTestCase
{
    public function test_authentication_middleware_added_after_synchronisation_reaches_a_matched_route(): void
    {
        $this->actingAsAdmin();
        $this->get(StandaloneCallbackRouteProvider::URI)->assertOk()->assertSee(StandaloneCallbackRouteProvider::BODY);

        Filament::getPanel('admin')->authMiddleware([PackageSecurityDenyMiddleware::class]);

        $this->get(StandaloneCallbackRouteProvider::URI)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_panel_middleware_added_after_synchronisation_reaches_a_matched_route(): void
    {
        $this->actingAsAdmin();
        $this->get(StandaloneCallbackRouteProvider::URI)->assertOk()->assertSee(StandaloneCallbackRouteProvider::BODY);

        Filament::getPanel('admin')->middleware([PackageSecurityDenyMiddleware::class]);

        $this->get(StandaloneCallbackRouteProvider::URI)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_panel_middleware_added_after_synchronisation_reaches_the_public_sign_in_route(): void
    {
        $this->get('/admin/login')->assertOk();

        Filament::getPanel('admin')->middleware([PackageSecurityDenyMiddleware::class]);

        $this->get('/admin/login')->assertForbidden()->assertSee(PackageSecurityDenyMiddleware::BODY);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), StandaloneCallbackRouteProvider::class];
    }
}
