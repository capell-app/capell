<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Bootstrap;

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Events\PageDeleted;
use Capell\Core\Events\PageSaved;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Events\FrontendContextResolved;
use Capell\Frontend\Listeners\OnFrontendContextResolved;
use Capell\Frontend\Listeners\PurgeCdnCacheOnPageChangeListener;
use Capell\Frontend\Observers\ErrorPageModelInvalidationObserver;
use Capell\Frontend\Support\Cache\FrontendCacheInvalidationObserver;
use Illuminate\Support\Facades\Event;

final class FrontendEventBootstrapper
{
    public function boot(): void
    {
        Event::listen(FrontendContextResolved::class, [OnFrontendContextResolved::class, 'handle']);
        Event::listen(PageSaved::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleSaved']);
        Event::listen(PageDeleted::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleDeleted']);
        Event::listen(PageUrlChanged::class, [PurgeCdnCacheOnPageChangeListener::class, 'handlePageUrlChanged']);
        Event::listen(FrontendSurrogateKeysInvalidated::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleSurrogateKeys']);

        // Error page dependencies span first- and third-party models, so these
        // listeners intentionally remain wildcard-scoped.
        Event::listen('eloquent.created: *', [ErrorPageModelInvalidationObserver::class, 'createdFromEvent']);
        Event::listen('eloquent.updated: *', [ErrorPageModelInvalidationObserver::class, 'updatedFromEvent']);
        Event::listen('eloquent.deleted: *', [ErrorPageModelInvalidationObserver::class, 'deletedFromEvent']);

        foreach ([Language::class, Layout::class, Media::class, PageUrl::class, Site::class, SiteDomain::class, Theme::class, Translation::class] as $model) {
            $model::observe(FrontendCacheInvalidationObserver::class);
        }
    }
}
