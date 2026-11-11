<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Withdraws everything the frontend cached for a site once its publication state has
 * changed and committed: model and listing caches, the Core cache generation, static
 * page artefacts, fragments and CDN surrogate keys. A failed step throws, so the caller
 * never records completion over stale public output.
 */
final class FlushSitePublicOutputAction
{
    use AsFake;
    use AsObject;

    public function handle(Site $site): void
    {
        $registry = resolve(CacheInvalidationRegistry::class);
        $registry->invalidateForModel(Page::class);
        $site->pages()->each(fn (Page $page) => $registry->invalidateChangedModel($page));
        // Ordinary model/listing/render-data invalidation is best effort. Fence
        // all Core entries with a persisted, acknowledged generation instead;
        // retain the registry's external artefact/dependency side effects above,
        // but never trust its cache writes before recording the repair marker.
        resolve(CapellCacheManager::class)->flushCacheOrFail();
        resolve(StaticPageArtifactStore::class)->purgeSite($site->id);
        // Per-key invalidation is best effort and cannot fence an in-flight
        // render. An acknowledged namespace rotation makes all old bytes
        // unreachable before repair completion, even when deletion/locks fail.
        resolve(FragmentCache::class)->flush();
        InvalidateFrontendSurrogateKeysAction::run(['site-' . $site->id], awaitCdn: true);

    }
}
