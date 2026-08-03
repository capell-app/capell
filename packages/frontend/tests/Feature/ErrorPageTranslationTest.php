<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a 404 the way the framework does at runtime: through the exception
 * handler, which is what registers the `errors` view namespace and therefore
 * what makes the package's `resources/views/errors` directory reachable.
 */
function renderNotFoundResponse(string $url = 'http://site.test/no-such-page'): Response
{
    $request = Request::create($url);

    app()->instance('request', $request);

    return resolve(ExceptionHandler::class)->render($request, new NotFoundHttpException);
}

beforeEach(function (): void {
    Lang::addLines([
        'errors.not_found_title' => 'Introuvable',
        'errors.not_found_message' => 'Introuvable',
        'errors.not_found_headline' => 'Page introuvable',
        'errors.not_found_description' => 'Cette page a été déplacée ou n’existe plus.',
        'errors.back_to_homepage' => 'Retour à l’accueil',
    ], 'fr', 'capell-frontend');
});

it('resolves 404 copy through the translator on a non-default-language site', function (): void {
    app()->setLocale('fr');

    $response = renderNotFoundResponse();
    $html = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(404);

    expect($html)
        ->toContain('Introuvable')
        ->toContain('Page introuvable')
        ->toContain('Cette page a été déplacée ou n’existe plus.')
        ->toContain('Retour à l’accueil')
        ->toContain('lang="fr"');

    expect($html)
        ->not->toContain('We can’t find that page')
        ->not->toContain('capell-frontend::errors.');
});

it('falls back to the English lines when the locale has no translations', function (): void {
    app()->setLocale('en');

    $html = (string) renderNotFoundResponse()->getContent();

    expect($html)
        ->toContain('We can’t find that page')
        ->toContain('The page you’re looking for may have moved or no longer exists.')
        ->toContain('lang="en"')
        ->not->toContain('capell-frontend::errors.');
});

it('keeps the translated error page anonymous-cacheable', function (): void {
    app()->setLocale('fr');

    $response = renderNotFoundResponse();
    $html = (string) $response->getContent();

    // The generated error pages are stored as one static file per host and
    // status (GenerateErrorPageCacheAction), so a single rendering is replayed
    // to every anonymous visitor. That is only sound while the markup carries
    // no per-visitor state and no response cookie.
    expect($response->headers->getCookies())->toBeEmpty();
    expect($response->headers->has('Set-Cookie'))->toBeFalse();

    expect($html)
        ->not->toContain('csrf-token')
        ->not->toContain('_token')
        ->not->toContain('wire:snapshot')
        ->not->toContain('wire:id');

    expect($html)->toBe((string) renderNotFoundResponse()->getContent());
});
