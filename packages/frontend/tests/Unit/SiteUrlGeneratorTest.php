<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Unit;

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Routing\SiteUrlGenerator;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('capell-frontend.use_site_domain_for_urls', true);
});

it('rewrites URLs to use the domain base when config is enabled', function (): void {
    $routes = new RouteCollection;
    $request = Request::create('https://example.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);

    $domain = SiteDomain::factory()->make([
        'domain' => 'example.test',
        'scheme' => 'https',
        'path' => null,
        'status' => true,
        'default' => true,
    ]);

    $state = resolve(FrontendState::class);
    $state->withDomain($domain);

    $generator = new SiteUrlGenerator($routes, $request);
    $url = $generator->to('/foo?bar=baz');

    expect($url)->toBe('https://example.test/foo?bar=baz');
});

it('returns original URL if config is disabled', function (): void {
    Config::set('capell-frontend.use_site_domain_for_urls', false);
    $routes = new RouteCollection;
    $request = Request::create('https://example.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);

    $generator = new SiteUrlGenerator($routes, $request);
    $url = $generator->to('/foo?bar=baz');

    expect($url)->toContain('/foo?bar=baz');
});

it('returns original URL if domain base is empty', function (): void {
    $routes = new RouteCollection;
    $request = Request::create('https://example.test/', \Symfony\Component\HttpFoundation\Request::METHOD_GET);

    $state = resolve(FrontendState::class);
    // Simulate no domain by not calling withDomain

    $generator = new SiteUrlGenerator($routes, $request);
    $url = $generator->to('/foo?bar=baz');

    expect($url)->toContain('/foo?bar=baz');
});

it('rewrites URLs correctly when site domain has a path', function (): void {
    $routes = new RouteCollection;
    $request = Request::create('https://foo.com/bar', \Symfony\Component\HttpFoundation\Request::METHOD_GET);

    $domain = SiteDomain::factory()->make([
        'domain' => 'foo.com',
        'scheme' => 'https',
        'path' => '/bar',
        'status' => true,
        'default' => true,
    ]);

    $state = resolve(FrontendState::class);
    $state->withDomain($domain);

    $generator = new SiteUrlGenerator($routes, $request);
    $url = $generator->to('/baz?x=1');

    expect($url)->toBe('https://foo.com/bar/baz?x=1');
});
