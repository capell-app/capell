<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\PageTree;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Loads a site-scoped branch of the admin page tree for a given actor and
 * returns only the pages that actor may view.
 *
 * Encapsulates the SiteScope query constraint together with per-row
 * visibility (view gate + page-type role restrictions) so controllers and
 * other callers cannot accidentally leak pages outside the actor's scope.
 *
 * @method static Collection<int, Page> run(Authenticatable $actor, ?int $parentId = null, ?int $siteId = null)
 */
final class LoadPageTreeBranchAction
{
    use AsObject;

    /**
     * @return Collection<int, Page>
     */
    public function handle(Authenticatable $actor, ?int $parentId = null, ?int $siteId = null): Collection
    {
        /** @var class-string<Page> $pageClass */
        $pageClass = Page::class;

        /** @var Collection<int, Page> $pages */
        $pages = SiteScope::applyForCurrentActor($pageClass::query())
            ->when(($parentId ?? 0) > 0, function (Builder $query) use ($parentId): void {
                $query->where('parent_id', $parentId);
            })
            ->when(($siteId ?? 0) > 0, function (Builder $query) use ($siteId): void {
                $query->where('site_id', $siteId);
            })
            ->with(['site', 'type.roleRestrictions', 'pageUrl.siteDomain'])
            ->orderBy('order')
            ->get();

        return $pages
            ->filter(fn (Page $page): bool => $this->actorCanViewPage($actor, $page))
            ->values();
    }

    /**
     * Whether the actor has at least one visible child under the given page.
     */
    public function hasVisibleChildren(Authenticatable $actor, Page $page): bool
    {
        /** @var Collection<int, Page> $children */
        $children = SiteScope::applyForCurrentActor($page->children()->getQuery())
            ->with(['site', 'type.roleRestrictions'])
            ->get();

        return $children->contains(fn (Page $child): bool => $this->actorCanViewPage($actor, $child));
    }

    public function actorCanViewPage(Authenticatable $actor, Page $page): bool
    {
        if (! Gate::forUser($actor)->allows('view', $page)) {
            return false;
        }

        return ! $actor instanceof AuthenticatableUser || $page->isAccessibleByUser($actor);
    }
}
