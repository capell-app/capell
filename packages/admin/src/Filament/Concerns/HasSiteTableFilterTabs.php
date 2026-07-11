<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Enums\CacheEnum;
use Capell\Admin\Support\Loader\SiteLoader;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property string $siteRelation
 *
 * @mixin ListRecords
 */
trait HasSiteTableFilterTabs
{
    /** @var int */
    private const SITE_TABS_TTL = 3600;

    public function getTabs(): array
    {
        if ($this->siteRelation === '' || SiteLoader::getTotalSites() < 2) {
            return [];
        }

        $model = $this->getSiteModel();
        $related = $this->getRelatedModel($model);

        $sites = $this->getCachedSites($model, $related);

        $tabs = $this->getDefaultTabs($related);

        $sites->each(function (Site $site) use (&$tabs): void {
            $tabs[$site->id] = $this->makeSiteTab($site);
        });

        return $tabs;
    }

    protected function hasNoSitesFilterTab(): bool
    {
        return true;
    }

    protected function shouldCacheSiteTableFilterTabs(): bool
    {
        return true;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    protected function modifySiteTableFilterTabsQuery(Builder $query): Builder
    {
        return $query->ordered();
    }

    /** @param Builder<Model> $query */
    protected function modifySiteTabRelationCountQuery(Builder $query, Model $related): void
    {
        $query->when(
            $related->hasNamedScope('enabled'),
            fn (Builder $query): Builder => $query->enabled(),
        );
    }

    /**
     * @return class-string<Site>
     */
    private function getSiteModel(): string
    {
        return Site::class;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function getRelatedModel(string $model): Model
    {
        $query = $model::query()->select(['id', 'name']);

        return $query->getRelation($this->siteRelation)->getRelated();
    }

    /**
     * @param  class-string<Model>  $model
     * @return EloquentCollection<int, Site>
     */
    private function getCachedSites(string $model, Model $related): EloquentCollection
    {
        if (! $this->shouldCacheSiteTableFilterTabs()) {
            return $this->getSites($related);
        }

        $actor = auth()->user();

        if ($actor instanceof Authenticatable && ! SiteScope::isGlobalActor($actor)) {
            return $this->getSites($related);
        }

        $cacheKey = CacheEnum::siteTabs($model, $this->siteRelation);
        $cachedSites = Cache::get($cacheKey);

        if ($this->isValidCachedSitesCollection($cachedSites)) {
            return $cachedSites;
        }

        if ($cachedSites !== null) {
            Cache::forget($cacheKey);
        }

        $sites = $this->getSites($related);

        Cache::put($cacheKey, $sites, self::SITE_TABS_TTL);

        return $sites;
    }

    private function isValidCachedSitesCollection(mixed $sites): bool
    {
        return $sites instanceof EloquentCollection
            && $sites->every(static fn (mixed $site): bool => $site instanceof Site);
    }

    /** @return EloquentCollection<int, Site> */
    private function getSites(Model $related): EloquentCollection
    {
        /** @var Builder<Site> $siteQuery */
        $siteQuery = Site::query();
        $query = SiteScope::applyForCurrentActor($siteQuery, 'id')->select(['id', 'name']);

        /** @var EloquentCollection<int, Site> $sites */
        $sites = $this->modifySiteTableFilterTabsQuery(
            $query->withCount([
                $this->siteRelation => function (Builder $query) use ($related): void {
                    $this->modifySiteTabRelationCountQuery($query, $related);
                },
            ]),
        )->get();

        return $sites;
    }

    /**
     * @return array<string, Tab>
     */
    private function getDefaultTabs(Model $related): array
    {
        $tabs = [
            'all' => Tab::make(__('capell-admin::generic.all')),
        ];

        if ($this->hasNoSitesFilterTab()) {
            $tabs['none'] = Tab::make(__('capell-admin::tab.none'))
                ->badge(fn (): int => $this->getNoneBadgeCount($related))
                ->modifyQueryUsing(function (Builder $query): void {
                    $query->whereNull('site_id');
                });
        }

        return $tabs;
    }

    private function getNoneBadgeCount(Model $related): int
    {
        return $related::query()
            ->whereNull('site_id')
            ->when(
                $related->hasNamedScope('enabled'),
                fn (Builder $query): Builder => $query->enabled(),
            )
            ->count();
    }

    private function makeSiteTab(Site $site): Tab
    {
        $relation = $this->siteRelation;
        $property = Str::snake($this->siteRelation) . '_count';

        return Tab::make($site->name)
            ->badge(static function () use ($site, $relation, $property): int {

                if ($site->hasAttribute($property)) {
                    return $site->getAttributeValue($property);
                }

                $site->loadCount($relation);

                return $site->getAttributeValue($property);
            })
            ->modifyQueryUsing(function (Builder $query) use ($site): void {
                $query->where('site_id', $site->id);
            });
    }
}
