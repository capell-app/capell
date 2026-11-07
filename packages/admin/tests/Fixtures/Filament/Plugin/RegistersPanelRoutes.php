<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use RuntimeException;

/** Registers a route in a panel's route group, running the panel's middleware as Filament's panel groups do. */
trait RegistersPanelRoutes
{
    /** @param  Closure(): Route  $register */
    private function panelRoute(string $panel, Closure $register): Route
    {
        $route = null;
        RouteFacade::middleware(['panel:' . $panel])->group(static function () use ($register, &$route): void {
            $route = $register();
        });
        throw_unless($route instanceof Route, RuntimeException::class, 'Expected the panel route.');

        return $route;
    }
}
