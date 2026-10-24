<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;

final class RemediatePrivateSiteSpecOutput
{
    public function handle(SiteSpecPrivacyRepaired $event): void
    {
        $registry = resolve(CacheInvalidationRegistry::class);
        $registry->invalidateForModel(Page::class);
        $event->site->pages()->each(fn (Page $page) => $registry->invalidateChangedModel($page));
        resolve(StaticPageArtifactStore::class)->purgeSite($event->site->id);
        InvalidateFrontendSurrogateKeysAction::run(['site-' . $event->site->id], awaitCdn: true);
    }
}
