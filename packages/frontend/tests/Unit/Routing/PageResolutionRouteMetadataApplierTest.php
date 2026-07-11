<?php

declare(strict_types=1);

use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Support\Routing\PageResolutionRouteMetadataApplier;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('applies page query metadata to the explicit request and route', function (): void {
    $request = Request::create('https://example.com/articles/first');
    $route = new Route(['GET'], '/placeholder', []);
    $route->bind($request);

    $request->setRouteResolver(fn (): Route => $route);

    resolve(PageResolutionRouteMetadataApplier::class)->apply($request, new PageResolutionData(
        page: null,
        params: ['category' => 'news'],
        slug: 'first',
        routeUri: '/articles/{pageQuery}',
        pageQuery: 'articles/first',
    ));

    expect($request->input('pageQuery'))->toBe('articles/first')
        ->and($request->query->get('pageQuery'))->toBe('articles/first')
        ->and($request->attributes->get('pageQuery'))->toBe('articles/first')
        ->and($route->uri())->toBe('/articles/{pageQuery}')
        ->and($route->parameter('page'))->toBe('articles/first')
        ->and($route->parameter('pageQuery'))->toBe('articles/first')
        ->and($route->parameter('pageQueryParams'))->toBe(['category' => 'news'])
        ->and($route->parameter('pageSlug'))->toBe('first');
});

it('applies page slug without requiring page query metadata', function (): void {
    $request = Request::create('https://example.com/articles/second');
    $route = new Route(['GET'], '/placeholder', []);
    $route->bind($request);

    $request->setRouteResolver(fn (): Route => $route);

    resolve(PageResolutionRouteMetadataApplier::class)->apply($request, new PageResolutionData(
        page: null,
        slug: 'second',
    ));

    expect($request->attributes->has('pageQuery'))->toBeFalse()
        ->and($route->parameter('pageSlug'))->toBe('second');
});
