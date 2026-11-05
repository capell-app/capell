<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Enums\TransferDownloadEnum;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Override;

/**
 * The router's route collection, recording on each route as it is added the {@see PanelOwnership} that
 * decides who serves it, while its action can still be inspected. The record travels with the route
 * through `route:cache`, so ownership is never reconstructed by a later scan or from a cached closure.
 */
final class ProvenanceRouteCollection extends RouteCollection
{
    /**
     * Make the router's uncached collection record ownership, adding its routes through it. A route already
     * recorded keeps its ownership; one added to a collection that replaced this one (Livewire's testing
     * helper swaps in its own) is recorded now, from its live action.
     */
    public static function install(Router $router): void
    {
        $registered = $router->getRoutes();
        if (! $registered instanceof RouteCollection || $registered instanceof self) {
            return;
        }

        $routes = new self;
        foreach ($registered->getRoutes() as $route) {
            $routes->add($route);
        }

        $router->setRoutes($routes);
    }

    #[Override]
    public function add(Route $route): Route
    {
        TransferDownloadEnum::takeOverRegisteredRoute($route);
        resolve(InstalledPanelRuntime::class)->recordOwnership($route);

        return parent::add($route);
    }
}
