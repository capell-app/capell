<?php

declare(strict_types=1);

use Capell\Frontend\Http\Middleware\SecurityResponseHeaders;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

it('adds the security response policy and secure transport policy', function (): void {
    config()->set('security.headers.enabled', true);
    config()->set('security.headers.hsts', [
        'enabled' => true,
        'max_age' => 86400,
        'include_subdomains' => true,
        'preload' => true,
    ]);
    $request = Request::create('https://example.test/');
    $response = new Response;

    $result = (new SecurityResponseHeaders)->handle($request, fn (): Response => $response);

    expect($result)->toBe($response)
        ->and($result->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($result->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($result->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($result->headers->get('Strict-Transport-Security'))->toBe('max-age=86400; includeSubDomains; preload');
});

it('leaves the response unchanged when security headers are disabled', function (): void {
    config()->set('security.headers.enabled', false);
    $response = new Response;

    $result = (new SecurityResponseHeaders)->handle(
        Request::create('http://example.test/'),
        fn (): Response => $response,
    );

    expect($result)->toBe($response)
        ->and($result->headers->has('X-Content-Type-Options'))->toBeFalse()
        ->and($result->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('keeps headers the response already carries and does not send a content security policy', function (): void {
    config()->set('security.headers.enabled', true);
    config()->set('security.headers.hsts', ['enabled' => true]);

    $response = new Response;
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Strict-Transport-Security', 'max-age=1');

    $result = (new SecurityResponseHeaders)->handle(Request::create('https://example.test/'), fn (): Response => $response);

    expect($result->headers->all('X-Frame-Options'))->toBe(['DENY'])
        ->and($result->headers->all('Strict-Transport-Security'))->toBe(['max-age=1'])
        ->and($result->headers->all('X-Content-Type-Options'))->toBe(['nosniff'])
        ->and($result->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('sends the security headers on a public frontend page in a core-only install', function (): void {
    $middleware = resolve(FrontendRouteMiddlewareRegistry::class)->all();

    expect($middleware[0])->toBe(SecurityResponseHeaders::class);

    Route::middleware($middleware)->get('/security-headers-proof', fn (): string => 'ok');

    $this->get('/security-headers-proof')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
