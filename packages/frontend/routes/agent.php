<?php

declare(strict_types=1);

use Capell\Frontend\Http\Controllers\Agent\NavigationController;
use Capell\Frontend\Http\Controllers\Agent\PagesController;
use Capell\Frontend\Http\Controllers\Agent\SearchController;
use Capell\Frontend\Http\Controllers\Agent\TaxonomiesController;
use Capell\Frontend\Http\Middleware\ResolveAgentSite;
use Illuminate\Support\Facades\Route;

Route::prefix('agent/v1')->name('capell-agent.')->middleware([ResolveAgentSite::class, 'throttle:capell-agent'])->group(function (): void {
    Route::get('taxonomies', TaxonomiesController::class)->name('taxonomies');
    Route::get('taxonomies/{key}/terms', TaxonomiesController::class)->where('key', '[A-Za-z0-9_.-]+')->name('terms');
    Route::get('search', SearchController::class)->name('search');
    Route::get('navigation', NavigationController::class)->name('navigation');
    Route::get('pages', PagesController::class)->name('pages');
});
