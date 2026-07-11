<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Database\Eloquent\Model;

final class FrontendCacheInvalidationObserver
{
    public function saved(Model $model): void
    {
        resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($model);
    }

    public function deleted(Model $model): void
    {
        $this->saved($model);
    }

    public function restored(Model $model): void
    {
        $this->saved($model);
    }
}
