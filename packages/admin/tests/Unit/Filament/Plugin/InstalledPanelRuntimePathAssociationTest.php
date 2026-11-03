<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Override;

/** Routes under the panel path belong to the panel whether or not they name or declare it. */
final class InstalledPanelRuntimePathAssociationTest extends AdminTestCase
{
    private const string BODY = 'path route served';

    public function test_an_unnamed_route_under_the_panel_path_carries_package_security(): void
    {
        Route::get('/admin/runtime-plain-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-plain-callback')
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

    public function test_the_panel_domain_decides_which_routes_under_its_path_belong_to_it(): void
    {
        Filament::getPanel('admin')->domains(['admin.capell.test', 'cms.capell.test']);
        Route::domain('cms.capell.test')->get('/admin/runtime-alias-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]);
        Route::domain('shop.capell.test')->get('/admin/runtime-foreign-callback', static fn (): string => self::BODY)->middleware(['web', Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('http://cms.capell.test/admin/runtime-alias-callback')->assertForbidden()->assertDontSee(self::BODY);
        $this->get('http://shop.capell.test/admin/runtime-foreign-callback')->assertOk()->assertSee(self::BODY);
    }

    public function test_a_route_claimed_by_two_panels_is_refused(): void
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
