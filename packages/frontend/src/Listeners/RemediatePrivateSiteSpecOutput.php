<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Models\Page;
use Capell\Frontend\Actions\InvalidateFrontendSurrogateKeysAction;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class RemediatePrivateSiteSpecOutput
{
    public function handle(SiteSpecPrivacyRepaired $event): void
    {
        $registry = resolve(CacheInvalidationRegistry::class);
        $registry->invalidateForModel(Page::class);
        $event->site->pages()->each(fn (Page $page) => $registry->invalidateChangedModel($page));
        InvalidateFrontendSurrogateKeysAction::run(['site-' . $event->site->id]);

        $store = resolve(StaticPageArtifactStore::class);
        $manifest = $store->readManifest();
        $retained = [];
        foreach (($manifest['artifacts'] ?? []) as $artifact) {
            if ((int) ($artifact['dependencies']['site_id'] ?? 0) !== $event->site->id) {
                $retained[] = $artifact;

                continue;
            }

            if (is_string($artifact['file'] ?? null)) {
                $store->forgetHtml($artifact['file']);
                throw_if(File::exists($store->root() . '/' . ltrim($artifact['file'], '/')), RuntimeException::class, 'A private import export could not be removed.');
            }
        }

        $manifest['artifacts'] = $retained;
        $store->writeManifest($manifest);
    }
}
