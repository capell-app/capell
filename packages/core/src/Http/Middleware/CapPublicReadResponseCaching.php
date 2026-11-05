<?php

declare(strict_types=1);

namespace Capell\Core\Http\Middleware;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Core\Support\PublicRead\PublicReadResponseStamps;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One rule for caching any response built from public-read eligibility
 * decisions (pages, feeds, agent and other public APIs), applied globally so
 * no controller has to remember it.
 *
 * When the request consulted the rules of any site (PublicReadResponseStamps):
 *
 * - no cache may keep the response past the earliest next transition among
 *   those sites: max-age and s-maxage are capped at the seconds remaining,
 *   and the stale windows (stale-while-revalidate, stale-if-error) at what is
 *   left after them;
 * - when a transition is due now or the rules cannot be read, every cache
 *   must revalidate (no-cache, must-revalidate, max-age=0, no stale windows);
 * - the ETag includes the stamps, so a copy built under rules that have since
 *   changed never revalidates, even when its bytes are unchanged.
 *
 * Lifetimes are only ever shortened. A response that no cache may store
 * (no-store) is left as it is. With nothing restricting public reads, the
 * response is untouched.
 *
 * @experimental
 */
final class CapPublicReadResponseCaching
{
    private const array STALE_WINDOWS = ['stale-while-revalidate', 'stale-if-error'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $consulted = resolve(PublicReadResponseStamps::class);

        if (! $consulted->consulted()) {
            return $response;
        }

        $state = resolve(PublicReadEligibilityState::class);
        $keys = [];
        $earliest = null;
        $unknown = false;

        foreach ($consulted->sites() as $siteId) {
            $stamp = $state->stamp($siteId);

            if (! $stamp instanceof PublicReadStampData) {
                $unknown = true;
                $keys[] = 'unreadable';

                continue;
            }

            $keys[] = $stamp->key();

            if ($stamp->validUntil instanceof CarbonImmutable && (! $earliest instanceof CarbonImmutable || $stamp->validUntil->lessThan($earliest))) {
                $earliest = $stamp->validUntil;
            }
        }

        $keys = array_values(array_unique($keys));

        if ($keys === [PublicReadStampData::UNRESTRICTED_KEY]) {
            return $response;
        }

        if ($unknown) {
            $this->cap($response, 0);
        } elseif ($earliest instanceof CarbonImmutable) {
            $this->cap($response, max(0, $earliest->getTimestamp() - CarbonImmutable::now()->getTimestamp()));
        }

        if ($response->getStatusCode() === Response::HTTP_OK) {
            $this->validate($response, $request, $keys);
        }

        return $response;
    }

    private function cap(Response $response, int $remaining): void
    {
        $headers = $response->headers;

        if ($headers->hasCacheControlDirective('no-store')) {
            return;
        }

        $headers->remove('Expires');
        $headers->removeCacheControlDirective('immutable');

        if ($remaining === 0) {
            $headers->removeCacheControlDirective('s-maxage');

            foreach (self::STALE_WINDOWS as $directive) {
                $headers->removeCacheControlDirective($directive);
            }

            $headers->addCacheControlDirective('no-cache');
            $headers->addCacheControlDirective('must-revalidate');
            $headers->addCacheControlDirective('max-age', '0');

            return;
        }

        $fresh = 0;

        foreach (['max-age', 's-maxage'] as $directive) {
            if ($headers->hasCacheControlDirective($directive)) {
                $lifetime = min($this->seconds($headers->getCacheControlDirective($directive)), $remaining);
                $headers->addCacheControlDirective($directive, (string) $lifetime);
                $fresh = max($fresh, $lifetime);
            }
        }

        // Without max-age a browser may reuse a cacheable response heuristically
        // (from Last-Modified), even when shared caches were given s-maxage.
        if (! $headers->hasCacheControlDirective('max-age') && ! $headers->hasCacheControlDirective('no-cache') && ! $headers->hasCacheControlDirective('private')
            && ($headers->hasCacheControlDirective('public') || $headers->hasCacheControlDirective('s-maxage'))) {
            $lifetime = $headers->hasCacheControlDirective('s-maxage') ? min($this->seconds($headers->getCacheControlDirective('s-maxage')), $remaining) : $remaining;
            $headers->addCacheControlDirective('max-age', (string) $lifetime);
            $fresh = max($fresh, $lifetime);
        }

        foreach (self::STALE_WINDOWS as $directive) {
            if (! $headers->hasCacheControlDirective($directive)) {
                continue;
            }

            $window = min($this->seconds($headers->getCacheControlDirective($directive)), $remaining - $fresh);

            if ($window > 0) {
                $headers->addCacheControlDirective($directive, (string) $window);
            } else {
                $headers->removeCacheControlDirective($directive);
            }
        }
    }

    /** @param list<string> $keys */
    private function validate(Response $response, Request $request, array $keys): void
    {
        $existing = $response->getEtag();
        $content = $response->getContent();

        if ($existing === null && $content === false) {
            return;
        }

        sort($keys);
        $response->setEtag(hash('sha256', implode("\n", $keys) . "\n" . ($existing ?? hash('sha256', (string) $content))));
        $response->isNotModified($request);
    }

    private function seconds(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
