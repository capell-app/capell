<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Filter before pagination: hiding table rows afterwards still discloses the trash count. */
final class ScopePageRestoreVisibilityAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function handle(Builder $query): Builder
    {
        // Defer evaluation until execution, so count callers can use the same policy in one read.
        return $query->withGlobalScope(self::class, function (Builder $scoped): void {
            $candidates = (clone $scoped)->withoutGlobalScope(self::class);
            // Visibility must cover all matching trash, independently of the result page or projection.
            $candidates->setQuery($candidates->getQuery()
                ->cloneWithout(['limit', 'offset', 'orders', 'columns', 'aggregate'])
                ->cloneWithoutBindings(['order', 'select']));
            $hiddenIds = $candidates->select($candidates->getModel()->qualifyColumn('*'))
                ->onlyTrashed()->with(['blueprint.roleRestrictions', 'site'])->get()
                ->reject(fn (Page $page): bool => Gate::allows('view', $page))->modelKeys();

            $scoped->whereNotIn($scoped->getModel()->getQualifiedKeyName(), $hiddenIds);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function count(Builder $query): int
    {
        return $query->withoutGlobalScope(self::class)
            ->with(['blueprint.roleRestrictions', 'site'])->get()
            ->filter(fn (Model $page): bool => ! $page instanceof Page || ! $page->trashed() || Gate::allows('view', $page))
            ->count();
    }
}
