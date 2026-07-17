<?php

declare(strict_types=1);

use Capell\Frontend\Http\Controllers\PageController;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

it('keeps the packaged frontend home route disabled by default', function (): void {
    expect(config('capell-frontend.register_home_route'))->toBeFalse()
        ->and(Route::has('home'))->toBeFalse()
        ->and(Route::has('capell-frontend.home'))->toBeFalse();
});

it('registers the packaged frontend home route when enabled before routes boot', function (): void {
    $originalRouter = Route::getFacadeRoot();
    $router = new Router(app('events'), app());
    config(['capell-frontend.register_home_route' => true]);
    Route::swap($router);

    try {
        require __DIR__ . '/../../routes/web.php';

        $router->getRoutes()->refreshNameLookups();
        $route = $router->getRoutes()->getByName('capell-frontend.home');

        expect($route)->not->toBeNull()
            ->and($route?->uri())->toBe('/')
            ->and($route?->getActionName())->toBe(PageController::class);
    } finally {
        config(['capell-frontend.register_home_route' => false]);
        Route::swap($originalRouter);
    }
});
