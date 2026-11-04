<?php

declare(strict_types=1);

use Capell\Admin\Http\Agent\AgentAdminToolController;
use Capell\Admin\Http\Middleware\EnforceLockdownAdminAccess;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\AdminRouteProvenance;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

$adminAgentRoutes = Route::prefix(trim(AdminPanelEntrypoint::path(), '/') . '/agent/v1');

if (($adminDomain = AdminPanelEntrypoint::domain()) !== null) {
    $adminAgentRoutes->domain($adminDomain);
}

// Recorded as admin code, so each route keeps its panel ownership through `route:cache`.
AdminRouteProvenance::group(static fn () => $adminAgentRoutes
    ->middleware(['web', Authenticate::class, EnforceLockdownAdminAccess::class, SetSitePermissionScope::class])
    ->name('capell-admin.agent.')
    ->group(function (): void {
        Route::get('tools', [AgentAdminToolController::class, 'definitions'])
            ->name('tools');

        Route::post('tools/invoke', [AgentAdminToolController::class, 'invoke'])
            ->name('tools.invoke');
    }));
