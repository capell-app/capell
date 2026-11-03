<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ShortCircuitMiddleware;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Override;

/** Package security runs in the panel's order: after authentication, before the route's own middleware. */
final class InstalledPanelRuntimeMiddlewareOrderTest extends AdminTestCase
{
    public function test_package_security_runs_before_a_route_middleware_that_answers_the_request(): void
    {
        $route = Route::get('/admin/runtime-short-circuit', static fn (): string => 'route served')
            ->middleware(['web', Authenticate::class, ShortCircuitMiddleware::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-short-circuit')
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(ShortCircuitMiddleware::BODY);

        $pipeline = resolve(Router::class)->gatherRouteMiddleware($route);
        $this->assertLessThan(array_search(ShortCircuitMiddleware::class, $pipeline, true), array_search(PackageSecurityDenyMiddleware::class, $pipeline, true));
        $this->assertGreaterThan(array_search(Authenticate::class, $pipeline, true), array_search(PackageSecurityDenyMiddleware::class, $pipeline, true));
    }

    public function test_a_route_that_answers_before_authentication_is_refused(): void
    {
        Route::get('/admin/runtime-early-answer', static fn (): string => 'route served')
            ->middleware(['web', ShortCircuitMiddleware::class, Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-early-answer')->assertStatus(503)->assertDontSee(ShortCircuitMiddleware::BODY);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), PackageSecurityDenyRuntimeProvider::class];
    }
}
