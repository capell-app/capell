<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Models\Site;
use Capell\Core\Support\PublicRead\PublicArtefactPublicationLock;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Rendered output can link to, list or describe a page whose public-read
 * eligibility just changed. Cached fragments are made unreachable, exported
 * static pages are removed and CDN copies are purged, for the affected site
 * or, when the change is not tied to one, for every site.
 */
final class WithdrawPublicOutputOnPublicReadEligibilityChanged
{
    public function handle(PublicReadEligibilityChanged $event): void
    {
        resolve(FragmentCache::class)->flush();

        $siteIds = $event->siteId !== null
            ? [$event->siteId]
            : array_values(array_map(intval(...), Site::query()->pluck('id')->all()));
        $store = resolve(StaticPageArtifactStore::class);
        $purge = static function () use ($store, $siteIds): void {
            foreach ($siteIds as $siteId) {
                $store->purgeSite($siteId);
            }
        };

        // Under the publication lock, so an export that passed its write-time
        // check before the change finishes first and is purged here.
        try {
            resolve(PublicArtefactPublicationLock::class)->hold($siteIds, PublicArtefactPublicationLock::LOCK_SECONDS, static fn () => $purge());
        } catch (LockTimeoutException $lockTimeoutException) {
            // Purging is always safe: never leave a copy because a writer stalled.
            report($lockTimeoutException);
            $purge();
        }

        if ($siteIds !== []) {
            InvalidateFrontendSurrogateKeysAction::run(array_map(static fn (int $siteId): string => 'site-' . $siteId, $siteIds), awaitCdn: true);
        }
    }
}
