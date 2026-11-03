<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/** Match-time coverage of routes under the admin panel, request by request. */
final class InstalledPanelRuntimeRouteCoverageTest extends AdminTestCase
{
    public function test_repeated_requests_do_not_grow_a_panel_route_middleware_stack(): void
    {
        $panel = Filament::getPanel('admin');
        $route = Route::get('/admin/runtime-repeat', static fn (): string => 'repeat')
            ->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
            ->name('filament.admin.runtime-repeat');
        $this->actingAsAdmin();

        $this->get('/admin/runtime-repeat')->assertOk();
        $count = count($route->middleware());
        for ($request = 0; $request < 100; $request++) {
            $this->get('/admin/runtime-repeat')->assertOk();
        }

        $this->assertCount($count, $route->middleware());
    }
}
