<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\ModelServing;

use Capell\Frontend\Contracts\RenderedModelTracker;
use Illuminate\Database\Eloquent\Model;

final class NullRenderedModelTracker implements RenderedModelTracker
{
    public function track(Model $model): void
    {
        //
    }

    public function trackByClass(Model $model, string $modelClass): void
    {
        //
    }

    public function tracked(string $modelType): int
    {
        return 0;
    }
}
