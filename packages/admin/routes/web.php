<?php

declare(strict_types=1);

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Http\Controllers\Extensions\ExtensionAssetController;
use Capell\Admin\Http\Controllers\FrontendResourceDebugOverlayController;
use Capell\Admin\Http\Controllers\FrontendResourceDebugOverlayScriptController;
use Capell\Admin\Http\Controllers\PageContentLockController;
use Capell\Admin\Http\Controllers\PagePreviewController;
use Capell\Admin\Http\Controllers\PageTreeController;
use Capell\Admin\Http\Controllers\Themes\ThemePreviewController;
use Capell\Admin\Http\Controllers\UpdateAuthenticatedAdminLanguageController;
use Capell\Admin\Http\Middleware\EnforceLockdownAdminAccess;
use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Support\AdminRouteProvenance;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

// Served by the Capell admin panel, which runs its whole stack on its domains for each route.
AdminRouteProvenance::entrypoint(static function (): void {
    // A preview signature only proves the link was issued: the viewer still signs in to the panel and its security.
    Route::get('theme-preview/{theme}/{site}/{page}', ThemePreviewController::class)
        ->middleware(['web', Authenticate::class, EnforceLockdownAdminAccess::class, SetSitePermissionScope::class, 'signed'])
        ->name('capell.admin.theme-preview');

    Route::get('preview/page/{page}', PagePreviewController::class)
        ->middleware(['web', Authenticate::class, EnforceLockdownAdminAccess::class, SetSitePermissionScope::class, 'signed'])
        ->name('capell.admin.preview-page');

    // Internal admin API routes - CSRF-protected, with site-scoped policy authorization.
    Route::prefix('api')
        ->middleware(['web', 'auth', EnforceLockdownAdminAccess::class, SetSitePermissionScope::class])
        ->name('capell-admin.api.')
        ->group(function (): void {
            Route::get('page-tree', [PageTreeController::class, 'children'])
                ->name('page-tree');

            Route::get('frontend-resource-debug-overlay', FrontendResourceDebugOverlayController::class)
                ->name('frontend-resource-debug-overlay');

            Route::get('frontend-resource-debug-overlay.js', FrontendResourceDebugOverlayScriptController::class)
                ->name('frontend-resource-debug-overlay-script');

            Route::post('pages/{page}/content-lock/heartbeat', [PageContentLockController::class, 'heartbeat'])
                ->name('pages.content-lock.heartbeat');

            Route::post('pages/{page}/content-lock/release', [PageContentLockController::class, 'release'])
                ->name('pages.content-lock.release');
        });

    Route::post('profile/language', UpdateAuthenticatedAdminLanguageController::class)
        ->middleware(['web', 'auth', EnforceLockdownAdminAccess::class])
        ->name('capell-admin.profile.language.update');

    Route::get('avatar/{initials}.svg', AdminAvatarController::class)
        ->middleware(['web', 'auth', EnforceLockdownAdminAccess::class])
        ->where('initials', '[^/]+')
        ->name('capell-admin.avatar');

    Route::get('extension-asset', ExtensionAssetController::class)
        ->middleware(['web', EnforceLockdownAdminAccess::class])
        ->name('capell-admin.extension-asset');
});
