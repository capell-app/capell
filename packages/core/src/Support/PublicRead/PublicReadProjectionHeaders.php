<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP caching for public projections served outside the page route
 * (llms.txt, markdown copies, sitemaps). Shared caches may keep a copy but
 * must revalidate it on every request, and the validator includes the
 * public-read eligibility stamp, so a copy built under rules that have since
 * changed is never reused, even when its bytes are unchanged.
 *
 * @experimental
 */
final class PublicReadProjectionHeaders
{
    public const string CACHE_CONTROL = 'public, no-cache';

    public const string UNKNOWN_RULES_CACHE_CONTROL = 'no-store, private';

    /**
     * Revalidate on every request; while the public-read rules cannot be read,
     * keep no copy at all.
     */
    public static function cacheControl(): string
    {
        return resolve(PublicReadEligibilityState::class)->stamp() instanceof PublicReadStampData
            ? self::CACHE_CONTROL
            : self::UNKNOWN_RULES_CACHE_CONTROL;
    }

    public static function etag(string $content): string
    {
        $stamp = resolve(PublicReadEligibilityState::class)->stamp()?->key() ?? 'unreadable';

        return '"' . hash('sha256', $stamp . "\n" . $content) . '"';
    }

    /**
     * Sets the revalidation headers and turns the response into a 304 when
     * the request already holds this exact copy.
     *
     * @template TResponse of Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    public static function apply(Response $response, Request $request, string $content): Response
    {
        $response->headers->set('Cache-Control', self::cacheControl());
        $response->headers->remove('Expires');
        $response->setEtag(trim(self::etag($content), '"'));
        $response->isNotModified($request);

        return $response;
    }
}
