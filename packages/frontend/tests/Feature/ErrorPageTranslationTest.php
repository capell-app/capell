<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Creator\PageCreator;
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

/**
 * CAP-0243(b): Core shipped two casings of the same not-found headline —
 * `capell::generic.page_not_found` ("Page Not Found"), which names the seeded
 * error page and blueprint and becomes its translation title, and
 * `capell::generic.error_404_headline` ("Page not found"), the rendered
 * per-status headline. Both reach public output through different rungs of the
 * copy ladder, so a casing split is a visible inconsistency and, as CAP-0241
 * found, a debugging trap. They are one string by contract.
 *
 * `capell-frontend::errors.not_found_headline` is deliberately NOT part of this
 * contract: it is the framework-level minimal page shown when no CMS site or
 * static artefact could be resolved at all, and its friendlier wording marks
 * that different surface.
 */
it('keeps the two generic not-found headline keys identical', function (): void {
    expect(__('capell::generic.page_not_found'))
        ->toBe(__('capell::generic.error_404_headline'))
        ->and(__('capell::generic.page_not_found'))->toBe('Page not found');
});

it('seeds the error page with the headline the rendered 404 copy uses', function (): void {
    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state(['language_id' => $language->id])
        ->create();

    $site = $siteDomain->site;
    $errorPage = resolve(PageCreator::class)->createErrorPage($site, $site->getAllLanguages());

    $translation = $errorPage->translations()->where('language_id', $language->id)->firstOrFail();
    $statusCopy = $translation->meta['error_status_copy'][404]['headline'] ?? null;

    expect($errorPage->name)->toBe(__('capell::generic.error_404_headline'))
        ->and($translation->title)->toBe(__('capell::generic.error_404_headline'))
        ->and($statusCopy)->toBe(__('capell::generic.error_404_headline'));
});
