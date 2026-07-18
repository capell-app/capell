<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PageUrlSiteDomainDiagnosticData;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static PageUrlSiteDomainDiagnosticData run(PageUrl $pageUrl, ?string $caller = null)
 */
class DiagnosePageUrlSiteDomainAction
{
    use AsFake;
    use AsObject;

    public function handle(PageUrl $pageUrl, ?string $caller = null): PageUrlSiteDomainDiagnosticData
    {
        $activeSiteDomain = $this->siteDomainQuery($pageUrl)->first(['id']);
        $trashedSiteDomain = $this->siteDomainQuery($pageUrl)
            ->withTrashed()
            ->whereNotNull('deleted_at')
            ->first(['id', 'deleted_at']);

        return new PageUrlSiteDomainDiagnosticData(
            pageUrlId: $pageUrl->getKey(),
            pageableId: is_numeric($pageUrl->pageable_id) ? (int) $pageUrl->pageable_id : null,
            pageableType: $pageUrl->pageable_type,
            siteId: is_numeric($pageUrl->site_id) ? (int) $pageUrl->site_id : null,
            languageId: is_numeric($pageUrl->language_id) ? (int) $pageUrl->language_id : null,
            siteDomainRelationLoaded: $pageUrl->relationLoaded('siteDomain'),
            loadedSiteDomainIsNull: $pageUrl->relationLoaded('siteDomain') && $pageUrl->getRelation('siteDomain') === null,
            activeSiteDomainExists: $activeSiteDomain instanceof SiteDomain,
            activeSiteDomainId: $activeSiteDomain instanceof SiteDomain ? (int) $activeSiteDomain->getKey() : null,
            trashedSiteDomainExists: $trashedSiteDomain instanceof SiteDomain,
            trashedSiteDomainId: $trashedSiteDomain instanceof SiteDomain ? (int) $trashedSiteDomain->getKey() : null,
            trashedSiteDomainDeletedAt: $trashedSiteDomain instanceof SiteDomain ? $trashedSiteDomain->deleted_at?->toISOString() : null,
            routeName: request()->route()?->getName(),
            requestPath: request()->path(),
            caller: $caller,
        );
    }

    /**
     * @return Builder<SiteDomain>
     */
    private function siteDomainQuery(PageUrl $pageUrl): Builder
    {
        return SiteDomain::query()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id);
    }
}
