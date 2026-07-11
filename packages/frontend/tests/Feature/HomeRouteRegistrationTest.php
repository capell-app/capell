<?php

declare(strict_types=1);

it('requires the packaged frontend home route to be explicitly enabled', function (): void {
    $config = file_get_contents(__DIR__ . '/../../config/capell-frontend.php');

    expect($config)->toContain("'register_home_route' => env('CAPELL_FRONTEND_REGISTER_HOME_ROUTE', false),");

    $routes = file_get_contents(__DIR__ . '/../../routes/web.php');

    expect($routes)
        ->toContain("config('capell-frontend.register_home_route', false)")
        ->toContain("Route::get('/', PageController::class)->name('home');");
});
