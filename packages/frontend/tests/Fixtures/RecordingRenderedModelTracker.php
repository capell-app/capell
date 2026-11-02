<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Capell\Frontend\Contracts\CapturesRenderedModelIdentities;
use Capell\Frontend\Data\RenderedModelIdentitiesData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Override;

/** Records tracked models the way a page cache that listens for them does. */
final class RecordingRenderedModelTracker implements CapturesRenderedModelIdentities
{
    /** @var array<string, list<string>> */
    public array $tracked = [];

    /** @var list<array<string, list<string>>> */
    private array $captures = [];

    #[Override]
    public function track(Model $model): void
    {
        $visited = [];
        $this->collect($model, $visited);
    }

    #[Override]
    public function trackByClass(Model $model, string $modelClass): void
    {
        $key = $model->getKey();

        if ((is_int($key) || is_string($key)) && is_a($modelClass, Model::class, true)) {
            $this->add((new $modelClass)->getMorphClass(), (string) $key);
        }
    }

    #[Override]
    public function tracked(string $modelType): int
    {
        return count($this->tracked[$modelType] ?? []);
    }

    #[Override]
    public function startCapturingIdentities(): void
    {
        $this->captures[] = [];
    }

    #[Override]
    public function stopCapturingIdentities(): RenderedModelIdentitiesData
    {
        return new RenderedModelIdentitiesData(array_pop($this->captures) ?? []);
    }

    #[Override]
    public function trackIdentities(RenderedModelIdentitiesData $identities): void
    {
        foreach ($identities->keysByType as $modelType => $keys) {
            foreach ($keys as $key) {
                $this->add($modelType, $key);
            }
        }
    }

    public function capturing(): bool
    {
        return $this->captures !== [];
    }

    /** @return list<string> */
    public function keysFor(Model $model): array
    {
        return $this->tracked[$model->getMorphClass()] ?? [];
    }

    /** @param array<string, true> $visited */
    private function collect(Model $model, array &$visited): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $identity = $model->getMorphClass() . ':' . $key;

        if (isset($visited[$identity])) {
            return;
        }

        $visited[$identity] = true;
        $this->add($model->getMorphClass(), (string) $key);

        foreach ($model->getRelations() as $relation) {
            $related = $relation instanceof Collection ? $relation->all() : [$relation];

            foreach ($related as $item) {
                if ($item instanceof Model) {
                    $this->collect($item, $visited);
                }
            }
        }
    }

    private function add(string $modelType, string $key): void
    {
        if (! in_array($key, $this->tracked[$modelType] ?? [], true)) {
            $this->tracked[$modelType][] = $key;
        }

        foreach (array_keys($this->captures) as $index) {
            if (! in_array($key, $this->captures[$index][$modelType] ?? [], true)) {
                $this->captures[$index][$modelType][] = $key;
            }
        }
    }
}
