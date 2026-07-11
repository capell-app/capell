<?php

declare(strict_types=1);

use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Marketplace\Http\Controllers\MarketplaceAccountConnectionCallbackController;
use Capell\Marketplace\Http\Controllers\MarketplaceInstallFlowCallbackController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::prefix(AdminPanelEntrypoint::path())
    ->middleware([
        'panel:admin',
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        SubstituteBindings::class,
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
        Authenticate::class,
    ])
    ->group(function (): void {
        Route::get('/marketplace/connection/callback', MarketplaceAccountConnectionCallbackController::class)
            ->name('capell-marketplace.account-connection.callback');
        Route::get('/marketplace/install-flow/callback', MarketplaceInstallFlowCallbackController::class)
            ->name('capell-marketplace.install-flow.callback');
    });
