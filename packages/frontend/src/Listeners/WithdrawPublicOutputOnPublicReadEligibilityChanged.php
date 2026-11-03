<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;

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

        foreach ($siteIds as $siteId) {
            $store->purgeSite($siteId);
        }

        if ($siteIds !== []) {
            InvalidateFrontendSurrogateKeysAction::run(array_map(static fn (int $siteId): string => 'site-' . $siteId, $siteIds), awaitCdn: true);
        }
    }
}
