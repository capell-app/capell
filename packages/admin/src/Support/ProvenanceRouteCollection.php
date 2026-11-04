<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Enums\TransferDownloadEnum;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Override;

/**
 * The router's route collection, recording on each route as it is registered whether it runs admin
 * code, while its action can still be inspected. The record travels with the route through
 * `route:cache`, so ownership is never reconstructed by a later scan or from a cached closure.
 */
final class ProvenanceRouteCollection extends RouteCollection
{
    #[Override]
    public function add(Route $route): Route
    {
        TransferDownloadEnum::takeOverRegisteredRoute($route);
        resolve(InstalledPanelRuntime::class)->recordRegisteredRoute($route);

        return parent::add($route);
    }
}
