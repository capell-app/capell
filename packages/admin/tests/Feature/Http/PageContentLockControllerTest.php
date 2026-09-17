<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

it('applies site permission scope before content lock authorization', function (): void {
    foreach ([
        'capell-admin.api.pages.content-lock.heartbeat',
        'capell-admin.api.pages.content-lock.release',
    ] as $routeName) {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        expect($route)->toBeInstanceOf(Route::class);
        assert($route instanceof Route);

        expect($route->gatherMiddleware())->toContain(SetSitePermissionScope::class);
    }
});
