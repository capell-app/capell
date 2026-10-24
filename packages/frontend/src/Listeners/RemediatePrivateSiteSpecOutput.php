<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Data\SiteSpec\SiteSpecPrivacyRepairData;
use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Events\SiteSpecPrivacyRepairing;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;

final class RemediatePrivateSiteSpecOutput
{
    public function handle(SiteSpecPrivacyRepairing $event): void
    {
        $registry = resolve(CacheInvalidationRegistry::class);
        $registry->invalidateForModel(Page::class);
        $event->site->pages()->each(fn (Page $page) => $registry->invalidateChangedModel($page));
        // Ordinary model/listing/render-data invalidation is best effort. Fence
        // all Core entries with a persisted, acknowledged generation instead;
        // retain the registry's external artefact/dependency side effects above,
        // but never trust its cache writes before recording the repair marker.
        resolve(CapellCacheManager::class)->flushCacheOrFail();
        resolve(StaticPageArtifactStore::class)->purgeSite($event->site->id);
        // Per-key invalidation is best effort and cannot fence an in-flight
        // render. An acknowledged namespace rotation makes all old bytes
        // unreachable before repair completion, even when deletion/locks fail.
        resolve(FragmentCache::class)->flush();
        InvalidateFrontendSurrogateKeysAction::run(['site-' . $event->site->id], awaitCdn: true);

        // Dispatch inline: a package failure must propagate to the importer
        // before it records completion, regardless of queue configuration.
        event(new SiteSpecPrivacyRepaired(new SiteSpecPrivacyRepairData(
            siteIds: [$event->site->id],
            domains: $event->site->siteDomains()->get()
                ->map(static fn (SiteDomain $domain): ?string => $domain->domain)
                ->filter(static fn (?string $domain): bool => $domain !== null && $domain !== '')
                ->unique()->values()->all(),
        )));
    }
}
