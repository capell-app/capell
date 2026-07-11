<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class PageHydrator
{
    public function __construct(private readonly PageModelCache $modelCache) {}

    /**
     * Resolve an ordered int[] of page IDs to a Collection<Page>, preserving order
     * and silently dropping IDs that resolve to null (deleted, unpublished, etc.).
     *
     * @param  int[]  $ids
     * @param  class-string<Model&Pageable<Model>>  $morphType
     * @return Collection<int, Model&Pageable<Model>>
     */
    public function hydrate(
        array $ids,
        string $morphType,
        ?Site $site,
        Language $language,
        bool $withParent = false,
        bool $withChildren = false,
        bool $withChildrenCount = false,
        bool $withDate = false,
        bool $withEvents = true,
    ): Collection {
        if ($ids === []) {
            return new Collection;
        }

        $models = new Collection;

        foreach ($ids as $id) {
            $model = $this->modelCache->get($morphType, $id, $site, $language, $withEvents);

            if ($model instanceof Pageable) {
                $models->push($model);
            }
        }

        if ($withParent) {
            $this->injectParents($models, $site, $language, $withEvents);
        }

        if ($withChildrenCount) {
            $this->loadChildrenCount($models);
        }

        if ($withChildren) {
            $this->loadChildren($models);
        }

        if ($withDate) {
            $this->loadCreators($models);
        }

        return $models;
    }

    /**
     * @param  Collection<int, Model&Pageable<Model>>  $models
     */
    private function injectParents(Collection $models, ?Site $site, Language $language, bool $withEvents): void
    {
        $models->each(function (Model $model) use ($site, $language, $withEvents): void {
            $parentId = $model->getAttribute('parent_id');

            if ($parentId === null) {
                return;
            }

            $parent = $this->modelCache->get(Page::class, $parentId, $site, $language, $withEvents);
            $model->setRelation('parent', $parent);

            if ($parent instanceof Page) {
                $model->setRelation('ancestors', new Collection([$parent]));
            }
        });
    }

    /**
     * @param  Collection<int, Model&Pageable<Model>>  $models
     */
    private function loadChildrenCount(Collection $models): void
    {
        $models->loadCount([
            'children' => fn ($query) => $query->publishedDate(),
        ]);
    }

    /**
     * @param  Collection<int, Model&Pageable<Model>>  $models
     */
    private function loadChildren(Collection $models): void
    {
        $models->load([
            'children' => fn ($query) => $query->publishedDate(),
        ]);
    }

    /**
     * @param  Collection<int, Model&Pageable<Model>>  $models
     */
    private function loadCreators(Collection $models): void
    {
        $models->load('creator');
    }
}
