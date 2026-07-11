<?php

declare(strict_types=1);

namespace Capell\Admin\Observers;

use Capell\Admin\Enums\CacheEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;

class LayoutObserver
{
    public function saved(Layout $layout): void
    {
        Cache::forget(CacheEnum::siteTabs(Site::class, 'layouts'));
    }

    public function deleted(Layout $layout): void
    {
        Cache::forget(CacheEnum::siteTabs(Site::class, 'layouts'));
    }
}
