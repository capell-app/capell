<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Illuminate\Support\Facades\Route;
use Override;

/** Only recognised public panel routes may skip the panel's authentication stack. */
final class InstalledPanelRuntimePublicRouteTest extends AdminTestCase
{
    private const string BODY = 'unauthenticated panel route served';

    public function test_a_panel_route_without_authentication_carries_the_host_and_package_stacks(): void
    {
        Route::get('/admin/runtime-unprotected', static fn (): string => self::BODY)->middleware(['panel:admin', 'web']);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-unprotected')
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(self::BODY);
    }

    public function test_a_guest_cannot_reach_a_panel_route_that_omitted_authentication(): void
    {
        Route::get('/admin/runtime-guest-callback', static fn (): string => self::BODY)->middleware(['web']);

        $this->get('/admin/runtime-guest-callback')->assertRedirect('/admin/login');
    }

    public function test_the_panel_login_stays_public_without_package_authentication(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee(PackageSecurityDenyMiddleware::BODY);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), PackageSecurityDenyRuntimeProvider::class];
    }
}
