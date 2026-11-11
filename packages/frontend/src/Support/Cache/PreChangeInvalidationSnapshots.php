<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * The state a change is about to remove, recorded before it happens. Invalidation runs
 * after commit, when a deleted page's translations are already deleted, a deleted or
 * moved translation is no longer among its owner's, and a model saved twice in one
 * transaction only remembers its last change. Planning from what loads afterwards would
 * miss exactly the languages, blueprint and site the old content was cached under.
 *
 * Snapshots accumulate per model instance until the after-commit invalidation consumes
 * them; a WeakMap lets an unconsumed snapshot die with its model.
 */
final class PreChangeInvalidationSnapshots
{
    /** @var WeakMap<Model, array{languages: list<int>, attributes: array<string, list<int>>}> */
    private WeakMap $snapshots;

    public function __construct()
    {
        $this->snapshots = new WeakMap;
    }

    /** Record what a model is about to lose, before an update or delete is written. */
    public function capture(Model $model, bool $deleting): void
    {
        if ($model instanceof Translation) {
            $this->remember($model, languages: $this->ids([
                $model->getOriginal('language_id'),
                $model->getAttribute('language_id'),
            ]));

            return;
        }

        if (! $model instanceof Pageable) {
            return;
        }

        $languages = [];

        if ($deleting && $model->exists) {
            // Deleting the page deletes its translations before invalidation runs.
            $languages = $model->relationLoaded('translations')
                ? $this->ids($model->getRelation('translations')->pluck('language_id')->all())
                : $this->ids($model->translations()->pluck('language_id')->all());
        }

        $attributes = [];

        foreach (['blueprint_id', 'site_id'] as $attribute) {
            $attributes[$attribute] = $this->ids([$model->getOriginal($attribute)]);
        }

        $this->remember($model, $languages, $attributes);
    }

    /** @return list<int> */
    public function languages(Model $model): array
    {
        return $this->snapshots[$model]['languages'] ?? [];
    }

    /** @return list<int> */
    public function originalIds(Model $model, string $attribute): array
    {
        return $this->snapshots[$model]['attributes'][$attribute] ?? [];
    }

    public function forget(Model $model): void
    {
        unset($this->snapshots[$model]);
    }

    /**
     * @param  list<int>  $languages
     * @param  array<string, list<int>>  $attributes
     */
    private function remember(Model $model, array $languages, array $attributes = []): void
    {
        $snapshot = $this->snapshots[$model] ?? ['languages' => [], 'attributes' => []];
        $snapshot['languages'] = $this->ids([...$snapshot['languages'], ...$languages]);

        foreach ($attributes as $attribute => $ids) {
            $snapshot['attributes'][$attribute] = $this->ids([...($snapshot['attributes'][$attribute] ?? []), ...$ids]);
        }

        $this->snapshots[$model] = $snapshot;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
