<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Events\InstalledRuntimeRefreshed;
use Capell\Frontend\Http\Middleware\RejectReservedFrontendDomains;
use Capell\Frontend\Http\Middleware\RejectReservedFrontendPaths;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;

it('refreshes an already consumed frontend middleware group after installed runtime changes', function (): void {
    $registry = resolve(FrontendRouteMiddlewareRegistry::class);
    $router = resolve(Router::class);
    $router->middlewareGroup('capell-frontend', $registry->all());

    $route = new Route(['GET'], '/runtime-middleware-proof', ['middleware' => ['capell-frontend'], 'uses' => static fn (): string => 'ok']);
    expect($router->gatherRouteMiddleware($route))->not->toContain(ThrottleRequests::class);

    $registry->append([ThrottleRequests::class]);
    Event::dispatch(new InstalledRuntimeRefreshed(new PackageData('vendor/runtime-proof', PackageTypeEnum::Plugin)));

    expect($router->getMiddlewareGroups()['capell-frontend'])->toBe($registry->all())
        ->and($router->gatherRouteMiddleware($route))->toContain(ThrottleRequests::class);
});

it('runs the reserved-domain guard before the reserved-path guard', function (): void {
    $middleware = (new FrontendRouteMiddlewareRegistry)->all();

    $domainPosition = array_search(RejectReservedFrontendDomains::class, $middleware, true);
    $pathPosition = array_search(RejectReservedFrontendPaths::class, $middleware, true);

    expect($domainPosition)->not->toBeFalse()
        ->and($pathPosition)->not->toBeFalse()
        ->and($domainPosition)->toBeLessThan($pathPosition);
});

it('runs both reservation guards before the web middleware', function (): void {
    $middleware = (new FrontendRouteMiddlewareRegistry)->all();

    $webPosition = array_search('web', $middleware, true);

    expect(array_search(RejectReservedFrontendDomains::class, $middleware, true))->toBeLessThan($webPosition)
        ->and(array_search(RejectReservedFrontendPaths::class, $middleware, true))->toBeLessThan($webPosition);
});
