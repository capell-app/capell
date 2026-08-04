<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Enums\VisitorLanguageDetection;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Capell\Frontend\Support\Locale\AcceptLanguageMatcher;
use Capell\Frontend\Support\Locale\VisitorLanguageCookie;
use Capell\Frontend\Support\Locale\VisitorLanguageMatch;
use Capell\Frontend\Support\Locale\VisitorLanguageSiblingResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a first-time visitor to the exact translation of the page they asked
 * for, when their browser says they cannot read the one they landed on.
 *
 * WHERE THIS SITS, AND WHY
 * ------------------------
 * Registered in `FrontendRouteMiddlewareRegistry` after the reserved-domain and
 * reserved-path rejections (a request for a reserved path must 404 before any
 * language logic considers it) and before `web`. Before `web` matters twice:
 *
 * - The public HTML cache middleware runs after `web`. Redirecting here means
 *   the cache never sees a request whose response depends on a header.
 * - `AddQueuedCookiesToResponse` is inside `web`, so the preference cookie is
 *   attached to the redirect response directly rather than queued.
 *
 * THE CACHE INVARIANT
 * -------------------
 * The HTML cache keys on host + path ALONE. The rendered bytes must therefore
 * stay a pure function of host + path. This middleware may redirect, or it may
 * do nothing — it must never alter a pass-through response, not even by adding
 * a `Vary` header, because that response is the one that gets cached and
 * replayed to everyone. `Vary: Accept-Language` and `no-store` go on the 302
 * only, which is never cached.
 */
final class DetectVisitorLanguage
{
    public function __construct(
        private readonly FrontendSettingsReaderInterface $settings,
        private readonly CrawlerDetector $crawlers,
        private readonly AcceptLanguageMatcher $matcher,
        private readonly VisitorLanguageCookie $cookie,
        private readonly VisitorLanguageSiblingResolver $siblings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldEvaluate($request)) {
            return $next($request);
        }

        $match = $this->siblings->resolve($request);

        // No exact sibling for this page means no redirect at all. We never
        // fall back to the language homepage.
        if (! $match instanceof VisitorLanguageMatch) {
            return $next($request);
        }

        return $this->redirect($request, $match);
    }

    /**
     * The guard ladder. Ordered cheapest-first, and every rung means "pass
     * through completely untouched".
     */
    private function shouldEvaluate(Request $request): bool
    {
        if ($this->settings->visitorLanguageDetection() !== VisitorLanguageDetection::Redirect) {
            return false;
        }

        if (! $request->isMethod(Request::METHOD_GET)) {
            return false;
        }

        // The visitor has already decided, whether by being redirected once or
        // by using the language switcher. Never re-evaluate.
        if ($this->cookie->present($request)) {
            return false;
        }

        if ($this->crawlers->isCrawler($request->headers->get('User-Agent'))) {
            return false;
        }

        // No header, or a bare wildcard, means no stated preference.
        if ($this->matcher->preferences($request->headers->get('Accept-Language')) === []) {
            return false;
        }

        return $this->isEntryNavigation($request);
    }

    /**
     * Only redirect on a genuine entry navigation.
     *
     * A cookie-less browser clicking around the site would otherwise be bounced
     * on every single internal link — a navigation trap. `Sec-Fetch-Site` is the
     * authoritative signal on every current browser; a same-host `Referer` is
     * the fallback for the rest.
     */
    private function isEntryNavigation(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');

        if ($fetchSite !== null) {
            return mb_strtolower(mb_trim($fetchSite)) !== 'same-origin';
        }

        $referer = $request->headers->get('Referer');

        if (! is_string($referer) || $referer === '') {
            return true;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);

        return ! is_string($refererHost)
            || mb_strtolower($refererHost) !== mb_strtolower($request->getHost());
    }

    private function redirect(Request $request, VisitorLanguageMatch $match): RedirectResponse
    {
        $target = $match->targetUrl;
        $query = $request->getQueryString();

        if (is_string($query) && $query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?') . $query;
        }

        $response = new RedirectResponse($target, Response::HTTP_FOUND);

        $response->headers->setCookie($this->cookie->make($match->targetTag, $request->isSecure()));

        // Lets the destination render a one-time "we moved you, go back?" notice
        // without its cached bytes knowing anything about this visitor.
        $response->headers->setCookie($this->cookie->makeOrigin(
            $request->fullUrl(),
            $match->currentTag,
            $request->isSecure(),
        ));

        // Scoped to the redirect. Attaching either header to the pass-through
        // response would poison the shared HTML cache entry for that page.
        $response->headers->set('Vary', 'Accept-Language');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
