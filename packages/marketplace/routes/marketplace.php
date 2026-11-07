<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Marketplace\Http\Controllers\MarketplaceAccountConnectionCallbackController;
use Capell\Marketplace\Http\Controllers\MarketplaceInstallFlowCallbackController;
use Illuminate\Support\Facades\Route;

// Served by the Capell admin panel on its path and domain, which runs its whole stack and authentication.
AdminRouteProvenance::entrypoint(static function (): void {
    Route::get('marketplace/connection/callback', MarketplaceAccountConnectionCallbackController::class)
        ->name('capell-marketplace.account-connection.callback');
    Route::get('marketplace/install-flow/callback', MarketplaceInstallFlowCallbackController::class)
        ->name('capell-marketplace.install-flow.callback');
});
