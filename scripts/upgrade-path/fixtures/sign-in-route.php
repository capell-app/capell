<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Lets the upgrade-path check act as the site's administrator. scripts/upgrade-path.sh adds
 * these routes to its own scratch application only, served on 127.0.0.1, because the admin
 * sign-in form cannot be driven without a browser.
 *
 * /upgrade-path-check/sign-in signs in through the panel's guard, so the application's sign-in
 * listeners run as they do for a person. /upgrade-path-check/session only places the
 * administrator in the session, so admin pages can be requested even when a sign-in listener
 * was already failing on the published release.
 */
$administrator = static function (): Authenticatable {
    $user = Filament::getDefaultPanel()->auth()->getProvider()->retrieveByCredentials([
        'email' => trim((string) file_get_contents(storage_path('app/upgrade-path-admin-email'))),
    ]);

    abort_if($user === null, 404, 'The administrator created before the upgrade no longer exists.');

    return $user;
};

Route::get('/upgrade-path-check/sign-in', static function () use ($administrator): ResponseFactory|Response {
    Filament::getDefaultPanel()->auth()->login($administrator());

    return response('signed in');
});

Route::get('/upgrade-path-check/session', static function () use ($administrator): ResponseFactory|Response {
    $panel = Filament::getDefaultPanel();
    $user = $administrator();

    request()->session()->put($panel->auth()->getName(), $user->getAuthIdentifier());
    request()->session()->put('password_hash_' . $panel->getAuthGuard(), $user->getAuthPassword());

    return response('session started');
});
