<?php

declare(strict_types=1);

namespace Capell\Admin\Observers;

use Capell\Admin\Enums\CacheEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;

final class PageObserver
{
    public function saved(Page $page): void
    {
        Cache::forget(CacheEnum::siteTabs(Site::class, 'pages'));
        $this->invalidatePageCache($page);
    }

    public function deleted(Page $page): void
    {
        Cache::forget(CacheEnum::siteTabs(Site::class, 'pages'));
        $this->invalidatePageCache($page);
    }

    private function invalidatePageCache(Page $page): void
    {
        if (! app()->bound('capell.frontend.page-cache-invalidator')) {
            return;
        }

        $cacheInvalidator = resolve('capell.frontend.page-cache-invalidator');
        $onSavedHandler = [$cacheInvalidator, 'onSaved'];

        if (! is_callable($onSavedHandler)) {
            return;
        }

        $onSavedHandler($page);
    }
}
