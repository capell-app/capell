<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Override;

/**
 * A panel's path only serves the routes the panel registered: a host route sharing it is refused on the
 * hosts the panel serves, rather than adopted into the panel, and a route outside it is the host's own.
 */
final class InstalledPanelRuntimePathAssociationTest extends AdminTestCase
{
    private const string BODY = 'path route served';

    /** A host route under the panel path is not adopted: it runs its own middleware and is reported. */
    public function test_a_host_route_under_the_panel_path_is_not_adopted_and_is_reported(): void
    {
        AdminRouteProvenance::host(static fn () => Route::get('/admin/runtime-plain-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]));

        $this->get('/admin/runtime-plain-callback')->assertRedirect('/admin/login')->assertDontSee(self::BODY);
        // Its own authentication admits the admin; the panel's package security is not added to a host route.
        $this->actingAsAdmin()->get('/admin/runtime-plain-callback')->assertOk()->assertSee(self::BODY);
        $this->assertContains('GET|HEAD admin/runtime-plain-callback -> admin', resolve(InstalledPanelRuntime::class)->panelPathCollisions());
    }

    public function test_a_route_the_panel_registered_under_its_path_carries_package_security(): void
    {
        Route::middleware(['panel:admin'])->group(static fn () => Route::get('/admin/runtime-panel-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]));
        $this->actingAsAdmin();

        $this->get('/admin/runtime-panel-callback')
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(self::BODY);
    }

    public function test_a_route_outside_the_panel_path_is_not_held_to_the_panel(): void
    {
        Route::get('/administrator/runtime-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('/administrator/runtime-callback')->assertOk()->assertSee(self::BODY);
    }

    public function test_the_panel_domain_decides_where_its_path_refuses_host_routes(): void
    {
        Filament::getPanel('admin')->domains(['admin.capell.test', 'cms.capell.test']);
        AdminRouteProvenance::host(static fn () => Route::domain('cms.capell.test')->get('/admin/runtime-alias-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]));
        AdminRouteProvenance::host(static fn () => Route::domain('shop.capell.test')->get('/admin/runtime-foreign-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]));
        $this->actingAsAdmin();

        $this->get('http://cms.capell.test/admin/runtime-alias-callback')->assertOk()->assertSee(self::BODY);
        $collisions = resolve(InstalledPanelRuntime::class)->panelPathCollisions();
        $this->assertContains('GET|HEAD admin/runtime-alias-callback -> admin', $collisions);
        $this->assertNotContains('GET|HEAD admin/runtime-foreign-callback -> admin', $collisions);
        $this->get('http://shop.capell.test/admin/runtime-foreign-callback')->assertOk()->assertSee(self::BODY);
    }

    public function test_unclassified_routes_match_the_entrypoint_domain_before_its_path(): void
    {
        config()->set('capell-admin.domain', 'admin.example.test');
        Filament::getPanel('admin')->domains(['admin.example.test']);
        Route::domain('shop.example.test')->get('/admin/help', static fn (): string => self::BODY);
        Route::domain('admin.example.test')->get('/admin/unclassified', static fn (): string => self::BODY);

        $this->get('http://shop.example.test/admin/help')->assertOk()->assertSee(self::BODY);
        $this->get('http://admin.example.test/admin/unclassified')->assertStatus(503)->assertDontSee(self::BODY);
    }

    /** Declaring a panel's middleware after registration, under another panel's path, does not make it either panel's. */
    public function test_a_route_given_another_panels_middleware_after_registration_is_refused(): void
    {
        resolve(InstalledPanelRuntime::class)->extend(Panel::make()->id('second')->path('second'));
        Route::get('/second/runtime-claimed', static fn (): string => self::BODY)->middleware(['panel:admin', 'web', Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('/second/runtime-claimed')->assertStatus(503)->assertDontSee(self::BODY);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), PackageSecurityDenyRuntimeProvider::class];
    }
}
