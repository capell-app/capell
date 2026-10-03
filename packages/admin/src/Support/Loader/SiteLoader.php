<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Loader;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class SiteLoader
{
    /** @return Collection<int, Site> */
    public static function all(): Collection
    {
        $model = self::getModel();

        return SiteAccess::current()->query($model)->ordered()->get();
    }

    public static function getDefault(): ?Site
    {
        $model = self::getModel();

        return SiteAccess::current()->query($model)->default()->first();
    }

    /** @return list<SiteDomain|string>|null */
    public static function getSiteDomainFromUrl(string $url): ?array
    {
        try {
            $resolution = ResolveSiteDomainAction::run(SiteRequestTargetData::fromUrl($url), self::getSites());
        } catch (InvalidArgumentException) {
            return null;
        }

        return $resolution === null
            ? null
            : [$resolution->siteDomain, $resolution->relativePath];
    }

    /** @return Collection<int, Site> */
    public static function getSites(): Collection
    {
        /** @var class-string<Site> $model */
        $model = Site::class;

        if (! resolve(RuntimeSchemaState::class)->hasTable((new $model)->getTable())) {
            return (new $model)->newCollection();
        }

        return SiteAccess::current()->query($model)
            ->select(['id', 'name'])
            ->with('defaultDomain')
            ->withWhereHas('siteDomains.language')
            ->ordered()
            ->get();
    }

    public static function getTotalSites(): int
    {
        return SiteAccess::current()->query(Site::class)->count();
    }

    public static function total(): int
    {
        $model = self::getModel();

        return SiteAccess::current()->query($model)->enabled()->count();
    }

    public static function getSite(int|string $siteId): Site
    {
        $model = self::getModel();

        return SiteAccess::current()->query($model)->with(['languages', 'language', 'siteDomains.language'])->findOrFail($siteId);
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
