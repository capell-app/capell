<?php

declare(strict_types=1);

namespace Capell\Frontend\Listeners;

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Events\PageSaved;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Frontend\Actions\PurgeCdnCacheByPageAction;
use Capell\Frontend\Jobs\PurgeCdnCacheJob;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Capell\Frontend\Support\Cache\FragmentCache;

class PurgeCdnCacheOnPageChangeListener
{
    public function handleSaved(PageSaved $event): void
    {
        if ($event->page instanceof Page) {
            resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($event->page);
            PurgeCdnCacheByPageAction::run($event->page);
        }
    }

    public function handleDeleted(PageDeleted $event): void
    {
        if ($event->page instanceof Page) {
            resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($event->page);
            PurgeCdnCacheByPageAction::run($event->page);
        }
    }

    public function handlePageUrlChanged(PageUrlChanged $event): void
    {
        $pageUrl = PageUrl::query()->find($event->page_url_id);

        if ($pageUrl instanceof PageUrl) {
            resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($pageUrl);
        }

        if ($event->page_id === null) {
            return;
        }

        $page = Page::query()->find($event->page_id);

        if ($page instanceof Page) {
            resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($page);
        }
    }

    public function handleSurrogateKeys(FrontendSurrogateKeysInvalidated $event): void
    {
        if ($event->surrogateKeys === []) {
            return;
        }

        $fragmentCache = resolve(FragmentCache::class);
        foreach ($event->surrogateKeys as $surrogateKey) {
            $fragmentCache->invalidateBySurrogateKey($surrogateKey);
        }

        if (! PurgeCdnCacheJob::hasConfiguredProvider()) {
            return;
        }

        $queue = config('capell-frontend.purge_queue', 'default');

        dispatch(new PurgeCdnCacheJob($event->surrogateKeys))
            ->onQueue(is_string($queue) ? $queue : 'default');
    }
}
