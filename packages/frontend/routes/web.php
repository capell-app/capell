<?php

declare(strict_types=1);

use Capell\Frontend\Http\Controllers\ActivityBeaconController;
use Capell\Frontend\Http\Controllers\LanguagePreferenceController;
use Capell\Frontend\Http\Controllers\PageController;
use Capell\Frontend\Http\Controllers\ShowPrivacyRuntimeScriptController;
use Illuminate\Support\Facades\Route;

Route::name('capell-frontend.')
    ->group(function (): void {
        // Under the already-reserved `_capell` prefix, so it can never collide
        // with a page URL, and outside the frontend middleware stack: it records
        // a preference and forwards, it never renders.
        Route::get('_capell/language-preference', LanguagePreferenceController::class)
            ->middleware('web')
            ->name('language-preference');

        Route::post('_capell/activity', ActivityBeaconController::class)
            ->name('activity');

        // The default consent runtime. No session middleware, so it sets no cookie and
        // a CDN can cache it; the URL carries the script's content hash. No `.js`
        // extension, so a web server rule for static script files never answers it.
        Route::get('_capell/privacy-runtime', ShowPrivacyRuntimeScriptController::class)
            ->name('privacy-runtime');

        Route::middleware('capell-frontend')
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
