<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;

final class RemediatePrivateSiteSpecOutput
{
    public function handle(SiteSpecPrivacyRepaired $event): void
    {
        $registry = resolve(CacheInvalidationRegistry::class);
        $registry->invalidateForModel(Page::class);
        $event->site->pages()->each(fn (Page $page) => $registry->invalidateChangedModel($page));
        resolve(StaticPageArtifactStore::class)->purgeSite($event->site->id);
        // Per-key invalidation is best effort and cannot fence an in-flight
        // render. An acknowledged namespace rotation makes all old bytes
        // unreachable before repair completion, even when deletion/locks fail.
        resolve(FragmentCache::class)->flush();
        InvalidateFrontendSurrogateKeysAction::run(['site-' . $event->site->id], awaitCdn: true);
    }
}
