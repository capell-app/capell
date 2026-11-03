<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * An opaque token that changes whenever public-read eligibility may have
 * changed. Stored projections (render data, sitemaps, llms.txt) include it in
 * their identity or record it at generation, and are discarded when it moves.
 *
 * The token lives in the shared cache. If the cache loses it, a new token is
 * issued, which only ever invalidates more than necessary.
 *
 * @experimental
 */
final readonly class PublicReadEligibilityRevision
{
    private const string CACHE_KEY = 'capell:public-read-eligibility:revision';

    public function __construct(private Repository $cache) {}

    public function current(): string
    {
        $revision = $this->cache->get(self::CACHE_KEY);

        if (is_string($revision) && $revision !== '') {
            return $revision;
        }

        $revision = (string) Str::ulid();
        $this->cache->add(self::CACHE_KEY, $revision);
        $stored = $this->cache->get(self::CACHE_KEY);

        return is_string($stored) && $stored !== '' ? $stored : $revision;
    }

    public function advance(): string
    {
        $revision = (string) Str::ulid();
        $this->cache->forever(self::CACHE_KEY, $revision);

        return $revision;
    }
}
