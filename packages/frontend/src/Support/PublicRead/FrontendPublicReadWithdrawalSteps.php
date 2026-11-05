<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\PublicRead;

use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicReadWithdrawalSteps;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;

/**
 * Rendered output can link to, list or describe a page whose public-read
 * eligibility just changed. Cached fragments are made unreachable, exported
 * static pages are removed and CDN copies are purged, for the affected site
 * or, when the change is not tied to one, for every site. Each is a separate
 * withdrawal step, so one failing never stops the others and stays owed
 * until it succeeds.
 */
final class FrontendPublicReadWithdrawalSteps
{
    public const string FRAGMENTS = 'frontend.fragments';

    public const string STATIC_EXPORTS = 'frontend.static-exports';

    public const string CDN = 'frontend.cdn';

    public static function register(PublicReadWithdrawalSteps $steps): void
    {
        $steps->register(self::FRAGMENTS, static function (?int $siteId): void {
            resolve(FragmentCache::class)->flush();
        });

        $steps->register(self::STATIC_EXPORTS, static function (?int $siteId): void {
            $store = resolve(StaticPageArtifactStore::class);
            $siteIds = self::siteIds($siteId);

            // Under the store's lock, the lock every export write is checked
            // and written under: an export that passed its check before the
            // change finishes first and is removed here.
            $store->exclusive(static function () use ($store, $siteIds): void {
                foreach ($siteIds as $id) {
                    $store->purgeSite($id);
                }
            });
        });

        $steps->register(self::CDN, static function (?int $siteId): void {
            $siteIds = self::siteIds($siteId);

            // Only the edge purge: fragments have their own step, so a broken
            // fragment cache never stops CDN copies being withdrawn.
            if ($siteIds !== [] && PurgeCdnCacheJob::hasConfiguredProvider()) {
                new PurgeCdnCacheJob(array_map(static fn (int $id): string => 'site-' . $id, $siteIds))->purgeImmediately();
            }
        });
    }

    /** @return list<int> */
    private static function siteIds(?int $siteId): array
    {
        return $siteId !== null
            ? [$siteId]
            : array_values(array_map(intval(...), Site::query()->pluck('id')->all()));
    }
}
