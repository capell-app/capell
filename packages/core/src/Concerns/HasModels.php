<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

trait HasModels
{
    /**
     * @var array<string, class-string<Model>>
     */
    protected ?array $models = null;

    /**
     * @return array<string, class-string<Model>>
     */
    public function getModels(): array
    {
        return $this->models ?? [];
    }

    /**
     * Register model classes for enumeration (used by model-event wiring and install commands).
     * To extend a model, bind it in the service container instead:
     *   $this->app->bind(OriginalModel::class, ExtendedModel::class);
     *
     * @param  array<int|string, BackedEnum|class-string<Model>>  $models
     */
    public function registerModels(array $models = []): static
    {
        $morphMap = [];

        foreach ($models as $model) {
            if ($model instanceof BackedEnum) {
                /** @var class-string<Model> $class */
                $class = $model->value;
                $this->models[$model->name] = $class;
                $morphMap[Str::snake($model->name)] = $class;
            } elseif (is_string($model)) {
                /** @var class-string<Model> $model */
                $name = class_basename($model);

                $this->models[$name] = $model;
                $morphMap[Str::snake($name)] = $model;
            }
        }

        if ($morphMap !== []) {
            Relation::morphMap($morphMap, merge: true);
        }

        return $this;
    }

    protected function flushModelRuntimeState(): void
    {
        $this->models = null;
    }
}
