<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Loader;

use Capell\Admin\Enums\CacheEnum;
use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class SiteLoader
{
    /** @return Collection<int, Site> */
    public static function all(): Collection
    {
        $model = self::getModel();

        return $model::query()->ordered()->get();
    }

    public static function getDefault(): ?Site
    {
        $model = self::getModel();

        return $model::getDefault();
    }

    /** @return list<SiteDomain|string>|null */
    public static function getSiteDomainFromUrl(string $url): ?array
    {
        return LoadSiteDomainFromUrlAction::run(url: $url, sites: self::getSites());
    }

    /** @return Collection<int, Site> */
    public static function getSites(): Collection
    {
        $cached = Cache::get(CacheEnum::SiteAll->value);

        if ($cached instanceof Collection) {
            return $cached;
        }

        Cache::forget(CacheEnum::SiteAll->value);

        /** @var class-string<Site> $model */
        $model = Site::class;

        if (! resolve(RuntimeSchemaState::class)->hasTable((new $model)->getTable())) {
            return (new $model)->newCollection();
        }

        $sites = $model::query()
            ->select(['id', 'name'])
            ->with('defaultDomain')
            ->withWhereHas('siteDomains.language')
            ->ordered()
            ->get();

        Cache::put(CacheEnum::SiteAll->value, $sites, 30);

        return $sites;
    }

    public static function getTotalSites(): int
    {
        return Cache::remember(
            CacheEnum::SiteTotal->value,
            30,
            fn (): int => Site::query()->count(),
        );
    }

    public static function total(): int
    {
        $model = self::getModel();

        return $model::query()->enabled()->count();
    }

    public static function getSite(int|string $siteId): Site
    {
        $model = self::getModel();

        return Cache::remember(
            CacheEnum::site($siteId),
            30,
            fn (): Site => $model::with(['languages', 'language', 'siteDomains.language'])
                ->findOrFail($siteId),
        );
    }

    public function loadById(int $siteId): Site
    {
        return self::getSite($siteId);
    }

    /**
     * @return class-string<Site>
     */
    private static function getModel(): string
    {
        return Site::class;
    }
}
