<?php

declare(strict_types=1);

use Capell\Installer\Http\Controllers\InstallController;
use Capell\Installer\Http\Middleware\EnsureNotInstalled;
use Illuminate\Support\Facades\Route;

Route::name('capell-installer.')
    ->middleware('web')
    ->group(function (): void {
        Route::middleware(EnsureNotInstalled::class)->group(function (): void {
            Route::get('install', [InstallController::class, 'show'])->name('show');
            Route::post('install', [InstallController::class, 'store'])->name('store');
            Route::post('install/run-step', [InstallController::class, 'runStep'])->name('run-step');
            Route::post('install/{installId}/cancel', [InstallController::class, 'cancel'])->name('cancel');
        });

        Route::post('install/delete-installer', [InstallController::class, 'destroy'])->name('destroy');
        Route::get('install/success/{installId}', [InstallController::class, 'success'])->name('success');
        Route::get('install/progress/{installId}', [InstallController::class, 'progress'])->name('progress');
        Route::get('install/progress/{installId}/data', [InstallController::class, 'progressData'])->name('progress.data');
        Route::get('install/progress/{installId}/download', [InstallController::class, 'report'])->name('progress.download');
        Route::get('install/progress/{installId}/report', [InstallController::class, 'report'])->name('progress.report');
    });
