<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ShortCircuitMiddleware;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

/** The host panel's authentication runs before any of the route's own middleware, with or without package middleware. */
final class InstalledPanelRuntimeHostOrderTest extends AdminTestCase
{
    public function test_a_route_middleware_that_answers_before_host_authentication_is_refused(): void
    {
        Route::get('/admin/runtime-host-early-answer', static fn (): string => 'route served')
            ->middleware(['web', ShortCircuitMiddleware::class, Authenticate::class]);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-host-early-answer')->assertStatus(503)->assertDontSee(ShortCircuitMiddleware::BODY);
    }

    public function test_a_route_middleware_after_host_authentication_still_runs_for_a_signed_in_admin(): void
    {
        Route::get('/admin/runtime-host-late-answer', static fn (): string => 'route served')
            ->middleware(['web', Authenticate::class, ShortCircuitMiddleware::class]);

        $this->get('/admin/runtime-host-late-answer')->assertRedirect('/admin/login');
        $this->actingAsAdmin();
        $this->get('/admin/runtime-host-late-answer')->assertOk()->assertSee(ShortCircuitMiddleware::BODY);
    }
}
