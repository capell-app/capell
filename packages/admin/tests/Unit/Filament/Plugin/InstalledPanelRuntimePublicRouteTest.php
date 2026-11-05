<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use Override;

/** Only recognised public panel routes may skip the panel's authentication stack. */
final class InstalledPanelRuntimePublicRouteTest extends AdminTestCase
{
    private const string BODY = 'unauthenticated panel route served';

    public function test_a_panel_route_without_authentication_carries_the_host_and_package_stacks(): void
    {
        Route::middleware(['panel:admin', 'web'])->group(static fn () => Route::get('/admin/runtime-unprotected', static fn (): string => self::BODY));
        $this->actingAsAdmin();

        $this->get('/admin/runtime-unprotected')
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(self::BODY);
    }

    /**
     * A host route on the panel's path that the panel did not register runs only its own middleware, like any
     * host route, and is reported as sharing the panel's URLs; a guest reaches it only if its middleware allows.
     */
    public function test_a_host_route_on_the_panel_path_runs_its_own_middleware_and_is_reported(): void
    {
        Route::get('/admin/runtime-guest-callback', static fn (): string => self::BODY)->middleware(['web']);
        Route::get('/admin/runtime-guarded-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]);

        $this->get('/admin/runtime-guest-callback')->assertOk()->assertSee(self::BODY);
        $this->get('/admin/runtime-guarded-callback')->assertRedirect()->assertDontSee(self::BODY);
        $this->assertContains('GET|HEAD admin/runtime-guest-callback -> admin', resolve(InstalledPanelRuntime::class)->panelPathCollisions());
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
