<?php

declare(strict_types=1);

use Capell\Frontend\Http\Middleware\SecurityResponseHeaders;
use Illuminate\Http\Request;
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
