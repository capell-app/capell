<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Database\Eloquent\Model;

/**
 * Runs inside the change, before it is written (deliberately not after commit), so the
 * after-commit invalidation can plan from the state the change removed.
 */
final class PreChangeInvalidationObserver
{
    public function updating(Model $model): void
    {
        resolve(PreChangeInvalidationSnapshots::class)->capture($model, deleting: false);
    }

    public function deleting(Model $model): void
    {
        resolve(PreChangeInvalidationSnapshots::class)->capture($model, deleting: true);
    }
}
