<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Support\PageRestoreReadOnlyScope;
use Illuminate\Database\Eloquent\Builder;
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
        $hiddenIds = PageRestoreReadOnlyScope::run($query->getModel()->getConnection(), static fn (): array => (clone $query)
            ->onlyTrashed()->with(['blueprint.roleRestrictions', 'site'])->get()
            ->reject(fn (Page $page): bool => Gate::allows('view', $page))->modelKeys());

        return $query->whereNotIn($query->getModel()->getQualifiedKeyName(), $hiddenIds);
    }
}
