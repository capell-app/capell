<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Concerns\RestoresSoftDeletedRelations;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Restore the standard observer relations in two statements, regardless of subtree size. */
final class RestorePageCascadeRelationsAction
{
    use AsFake;
    use AsObject;
    use RestoresSoftDeletedRelations;

    /** @param list<int> $ids */
    public function handle(Page $page, array $ids): void
    {
        $this->restoreRelation(Relation::noConstraints(fn (): MorphMany => $page->pageUrls()), $ids);
        $this->restoreRelation(Relation::noConstraints(fn (): HasMany|MorphMany => $page->translations()), $ids);
    }

    public function restoreAdditionalRelations(Page $page): void
    {
        $this->restoreSoftDeletedRelations($page, ['widgets', 'sections', 'assetAttachments']);
    }

    /**
     * @template TRelatedModel of Model
     *
     * @param  HasMany<TRelatedModel, Page>|MorphMany<TRelatedModel, Page>  $relation
     * @param  list<int>  $ids
     */
    private function restoreRelation(HasMany|MorphMany $relation, array $ids): void
    {
        $query = $relation->getQuery()->whereIn($relation->getQualifiedForeignKeyName(), $ids);
        if ($relation instanceof MorphMany) {
            $query->where($relation->getMorphType(), $relation->getMorphClass());
        }

        $query->onlyTrashed()->restore();
    }
}
