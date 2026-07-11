<?php

declare(strict_types=1);

use Capell\Frontend\Http\Middleware\ETagMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

afterEach(function (): void {
    Date::setTestNow();
});

it('adds weak etags and last modified headers to html and json responses', function (): void {
    Date::setTestNow('2026-05-07 10:15:00');

    $request = Request::create('/page');
    $response = (new ETagMiddleware)->handle($request, fn (): Response => new Response(
        '<html>Content</html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    ));

    expect($response->headers->get('ETag'))->toStartWith('W/"')
        ->and($response->headers->get('Last-Modified'))->toBe('Thu, 07 May 2026 10:15:00 GMT');

    $jsonResponse = (new ETagMiddleware)->handle($request, fn (): Response => new Response(
        '{"ok":true}',
        Response::HTTP_NOT_FOUND,
        ['Content-Type' => 'application/json'],
    ));

    expect($jsonResponse->headers->get('ETag'))->toStartWith('W/"');
});

it('returns not modified when request etag matches response content', function (): void {
    $request = Request::create('/page');
    $firstResponse = (new ETagMiddleware)->handle($request, fn (): Response => new Response(
        '<html>Content</html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=60'],
    ));

    $matchingRequest = Request::create('/page', server: [
        'HTTP_IF_NONE_MATCH' => (string) $firstResponse->headers->get('ETag'),
    ]);

    $secondResponse = (new ETagMiddleware)->handle($matchingRequest, fn (): Response => new Response(
        '<html>Content</html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=60'],
    ));

    expect($secondResponse->getStatusCode())->toBe(304)
        ->and($secondResponse->getContent())->toBe('')
        ->and($secondResponse->headers->get('ETag'))->toBe($firstResponse->headers->get('ETag'))
        ->and($secondResponse->headers->get('Cache-Control'))->toContain('max-age=60');
});

it('leaves non-cacheable response blueprints unchanged', function (): void {
    $response = (new ETagMiddleware)->handle(
        Request::create('/download'),
        fn (): Response => new Response('file', Response::HTTP_FOUND, ['Content-Type' => 'application/octet-stream']),
    );

    expect($response->headers->has('ETag'))->toBeFalse()
        ->and($response->headers->has('Last-Modified'))->toBeFalse();
});
