<?php

declare(strict_types=1);

use Capell\Frontend\Http\Middleware\RejectReservedFrontendDomains;
use Capell\Frontend\Http\Middleware\RejectReservedFrontendPaths;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;

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
