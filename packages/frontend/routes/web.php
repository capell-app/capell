<?php

declare(strict_types=1);

use Capell\Frontend\Http\Controllers\LanguagePreferenceController;
use Capell\Frontend\Http\Controllers\PageController;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Illuminate\Support\Facades\Route;

Route::name('capell-frontend.')
    ->group(function (): void {
        // Under the already-reserved `_capell` prefix, so it can never collide
        // with a page URL, and outside the frontend middleware stack: it records
        // a preference and forwards, it never renders.
        Route::get('_capell/language-preference', LanguagePreferenceController::class)
            ->middleware('web')
            ->name('language-preference');

        Route::middleware(resolve(FrontendRouteMiddlewareRegistry::class)->all())
            ->group(function (): void {
                if (config('capell-frontend.register_home_route', false)) {
                    Route::get('/', PageController::class)->name('home');
                }

                Route::get('index.php', PageController::class)->name('index.php');

                Route::get('{url}', PageController::class)
                    ->fallback()
                    ->where('url', config('capell-frontend.route.url_regex', '.*'))
                    ->name('page');
            });
    });
