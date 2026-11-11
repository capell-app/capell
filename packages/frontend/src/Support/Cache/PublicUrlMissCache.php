<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Remembers, for a few seconds, that a public URL resolved to no page, so a
 * burst of bot probes costs one resolution rather than one per request.
 *
 * Every entry carries a generation. Saving, deleting or restoring a page or
 * page URL bumps it, which retires all entries at once, so a newly published
 * page is never hidden behind a remembered miss.
 */
final class PublicUrlMissCache
{
    public const int TTL_SECONDS = 30;

    private const string GENERATION_KEY = 'capell:frontend:url-miss:generation';

    public static function knowsMiss(Site $site, Language $language, string $url): bool
    {
        try {
            return Cache::get(self::key($site, $language, $url)) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function rememberMiss(Site $site, Language $language, string $url): void
    {
        try {
            Cache::put(self::key($site, $language, $url), true, self::TTL_SECONDS);
        } catch (Throwable) {
            // A miss that cannot be remembered is simply resolved again.
        }
    }

    public static function invalidate(): void
    {
        try {
            Cache::add(self::GENERATION_KEY, 0);
            Cache::increment(self::GENERATION_KEY);
        } catch (Throwable) {
            // Without a cache nothing is remembered, so nothing is stale.
        }
    }

    private static function key(Site $site, Language $language, string $url): string
    {
        $generation = (int) Cache::get(self::GENERATION_KEY, 0);

        return sprintf(
            'capell:frontend:url-miss:%d:%d:%d:%s',
            $generation,
            $site->getKey(),
            $language->getKey(),
            hash('xxh128', $url),
        );
    }
}
