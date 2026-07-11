<?php

declare(strict_types=1);

namespace Capell\Admin\Support\HeaderNavigation;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class HeaderNavigationAccessResolver
{
    public function canUseNavigation(?Authenticatable $actor): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        return ! ($actor instanceof FilamentUser && ! $actor->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * @return Collection<int, Site>
     */
    public function visibleSites(Authenticatable $actor): Collection
    {
        if (! $this->canUseNavigation($actor)) {
            return collect();
        }

        /** @var Builder<Site> $query */
        $query = Site::query()
            ->select(['id', 'name', 'order', 'default'])
            ->with('defaultDomain')
            ->ordered();

        if (! $this->isGlobalActor($actor)) {
            $assignedSiteIds = $this->assignedSiteIds($actor);

            if ($assignedSiteIds === []) {
                return collect();
            }

            $query->whereIn('id', $assignedSiteIds);
        }

        return $query->get()
            ->filter(fn (Site $site): bool => $this->canViewAnyPagesForSite($actor, $site))
            ->values();
    }

    public function canViewAnyPagesForSite(Authenticatable $actor, Site|int $site): bool
    {
        $siteId = $site instanceof Site ? (int) $site->getKey() : $site;

        if (! $this->canUseSite($actor, $siteId)) {
            return false;
        }

        return $this->withSitePermissionScope(
            $siteId,
            fn (): bool => Gate::forUser($actor)->allows('viewAny', Page::class),
        );
    }

    public function canViewPage(Authenticatable $actor, Page $page): bool
    {
        $siteId = (int) $page->site_id;

        if (! $this->canViewAnyPagesForSite($actor, $siteId)) {
            return false;
        }

        return $this->withSitePermissionScope($siteId, function () use ($actor, $page): bool {
            if (! Gate::forUser($actor)->allows('view', $page)) {
                return false;
            }

            return ! $actor instanceof AuthenticatableUser || $page->isAccessibleByUser($actor);
        });
    }

    public function editUrlFor(Page $page): ?string
    {
        try {
            $url = GetEditPageResourceUrlAction::run($page);
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function siteEditUrlFor(Site $site): ?string
    {
        try {
            $resourceClass = AdminSurfaceLookup::resource(ResourceEnum::Site);

            return $resourceClass::getUrl('edit', ['record' => $site]);
        } catch (Throwable) {
            return null;
        }
    }

    public function publicUrlFor(Page $page): ?string
    {
        if ($page->trashed() || $page->isPending() || $page->isExpired()) {
            return null;
        }

        $pageUrl = $page->relationLoaded('pageUrl')
            ? $page->pageUrl
            : null;

        if (! $pageUrl instanceof PageUrl || ! $pageUrl->status) {
            return null;
        }

        $siteDomain = $pageUrl->relationLoaded('siteDomain')
            ? $pageUrl->siteDomain
            : null;

        if (! $siteDomain instanceof SiteDomain || ! $siteDomain->status) {
            return null;
        }

        return PageUrlPresenter::fullUrl($pageUrl);
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function constrainPageQueryForActor(Builder $query, Authenticatable $actor, int $siteId): Builder
    {
        if (! $this->canViewAnyPagesForSite($actor, $siteId)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where($query->qualifyColumn('site_id'), $siteId);

        return $query->whereHas(
            'type',
            fn (BuilderContract $query): BuilderContract => $this->constrainBlueprintQueryForActor($query, $actor, $siteId),
        );
    }

    /**
     * @param  Builder<Page>  $query
     * @param  list<int>  $siteIds
     * @return Builder<Page>
     */
    public function constrainPageQueryForSites(Builder $query, Authenticatable $actor, array $siteIds): Builder
    {
        $visibleSiteIds = collect($siteIds)
            ->map(fn (int|string $siteId): int => $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0 && $this->canViewAnyPagesForSite($actor, $siteId))
            ->values()
            ->all();

        if ($visibleSiteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn($query->qualifyColumn('site_id'), $visibleSiteIds)
            ->where(function (Builder $query) use ($actor, $visibleSiteIds): void {
                foreach ($visibleSiteIds as $siteId) {
                    $query->orWhere(function (Builder $query) use ($actor, $siteId): void {
                        $query
                            ->where($query->qualifyColumn('site_id'), $siteId)
                            ->whereHas(
                                'type',
                                fn (BuilderContract $query): BuilderContract => $this->constrainBlueprintQueryForActor($query, $actor, $siteId),
                            );
                    });
                }
            });
    }

    public function constrainBlueprintQueryForActor(BuilderContract $query, Authenticatable $actor, int $siteId): BuilderContract
    {
        $roleIds = $this->roleIdsForSite($actor, $siteId);

        return $query->where(function (BuilderContract $query) use ($roleIds): void {
            $query->whereDoesntHave('roleRestrictions')
                ->orWhereHas(
                    'roleRestrictions',
                    fn (BuilderContract $query): BuilderContract => $query->whereIn('role_id', $roleIds),
                );
        });
    }

    public function canUseSite(Authenticatable $actor, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        if ($this->isGlobalActor($actor)) {
            return true;
        }

        return in_array($siteId, $this->assignedSiteIds($actor), true);
    }

    /**
     * @return list<int>
     */
    public function visibleSiteIds(Authenticatable $actor): array
    {
        return array_values($this->visibleSites($actor)
            ->pluck('id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->values()
            ->all());
    }

    public function isGlobalActor(Authenticatable $actor): bool
    {
        return SiteScope::isGlobalActor($actor);
    }

    /**
     * @return list<int>
     */
    private function assignedSiteIds(Authenticatable $actor): array
    {
        if (! method_exists($actor, 'getAssignedSiteIds')) {
            return [];
        }

        return array_values($actor->getAssignedSiteIds()
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return list<int>
     */
    private function roleIdsForSite(Authenticatable $actor, int $siteId): array
    {
        if (! $actor instanceof AuthenticatableUser) {
            return [];
        }

        $tableNames = config('permission.table_names', []);
        $modelHasRolesTable = is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
        $teamColumnConfig = config('permission.column_names.team_foreign_key', 'team_id');
        $teamColumn = is_string($teamColumnConfig) && $teamColumnConfig !== '' ? $teamColumnConfig : 'team_id';

        return array_values(collect(DB::table($modelHasRolesTable)
            ->where('model_type', $actor->getMorphClass())
            ->where('model_id', $actor->getKey())
            ->where(function (QueryBuilder $query) use ($teamColumn, $siteId): void {
                $query->whereNull($teamColumn)
                    ->orWhere($teamColumn, $siteId);
            })
            ->pluck('role_id'))
            ->map(fn (int|string $roleId): int => (int) $roleId)
            ->values()
            ->all());
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withSitePermissionScope(int $siteId, Closure $callback): mixed
    {
        $registrar = resolve(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($siteId);

            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
