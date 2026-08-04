<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Loader\SiteLoader;
use Capell\Frontend\Support\Locale\AcceptLanguageMatcher;
use Capell\Frontend\Support\Locale\VisitorLanguageCookie;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the visitor's one explicit language signal and forwards them on.
 *
 * The language switcher is rendered by the theme, which lives outside this
 * package, so the mechanism has to be callable from plain markup: a link.
 * Wrapping the switcher's existing href in this route is the whole integration.
 *
 * `to` is validated against the site domains this installation actually serves,
 * so the route cannot be used as an open redirect.
 */
final class LanguagePreferenceController
{
    public function __construct(
        private readonly VisitorLanguageCookie $cookie,
        private readonly AcceptLanguageMatcher $matcher,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $target = $this->safeTarget($request);
        $tag = $this->matcher->normalise((string) $request->query('language', ''));

        $response = new RedirectResponse($target, Response::HTTP_FOUND);

        if ($tag !== '' && preg_match('/^[a-z]{2,8}(-[a-z0-9]{2,8})*$/', $tag) === 1) {
            $response->headers->setCookie($this->cookie->make($tag, $request->isSecure()));
        }

        // Never cache the act of recording a preference.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function safeTarget(Request $request): string
    {
        $to = $request->query('to');
        $fallback = $request->getSchemeAndHttpHost() . '/';

        if (! is_string($to) || $to === '') {
            return $fallback;
        }

        $sites = SiteLoader::getSites();

        if ($sites->isEmpty()) {
            return $fallback;
        }

        $resolved = LoadSiteDomainFromUrlAction::run($to, sites: $sites);

        if (! is_array($resolved) || ! $resolved[0] instanceof SiteDomain) {
            return $fallback;
        }

        return $to;
    }
}
