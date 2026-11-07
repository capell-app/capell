<?php

declare(strict_types=1);

use Capell\Admin\Http\Agent\AgentAdminToolController;
use Capell\Admin\Http\Middleware\EnforceLockdownAdminAccess;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Http\Middleware\ThrottleAgentToolInvocations;
use Capell\Admin\Support\AdminRouteProvenance;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

// Served by the Capell admin panel, which runs its whole stack on its domains for each route.
AdminRouteProvenance::entrypoint(static function (): void {
    Route::prefix('agent/v1')
        ->middleware(['web', Authenticate::class, EnforceLockdownAdminAccess::class, SetSitePermissionScope::class])
        ->name('capell-admin.agent.')
        ->group(static function (): void {
            Route::get('tools', [AgentAdminToolController::class, 'definitions'])
                ->name('tools');

            Route::post('tools/invoke', [AgentAdminToolController::class, 'invoke'])
                ->middleware(ThrottleAgentToolInvocations::class)
                ->name('tools.invoke');
        });
});
