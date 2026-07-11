<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Illuminate\Database\Eloquent\Model;

interface RenderedModelTracker
{
    public function track(Model $model): void;

    /**
     * @param  class-string  $modelClass
     */
    public function trackByClass(Model $model, string $modelClass): void;

    public function tracked(string $modelType): int;
}
