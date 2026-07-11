<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\CapellCoreHelper;

class SiteDomainObserver
{
    public function saving(SiteDomain $siteDomain): void
    {
        if ($siteDomain->path !== null && ! str_starts_with($siteDomain->path, '/')) {
            $siteDomain->path = '/' . $siteDomain->path;
        }

        if ($siteDomain->getAttribute('default') === null) {
            $siteDomain->default = ! $siteDomain->site->hasDefaultDomain();
        }
    }

    public function saved(SiteDomain $siteDomain): void
    {
        $siteDomain->site->translations()->createOrFirst([
            'language_id' => $siteDomain->language_id,
        ], [
            'title' => $siteDomain->site->name,
        ]);

        $this->flushSiteCaches($siteDomain);
    }

    public function deleted(SiteDomain $siteDomain): void
    {
        $this->flushSiteCaches($siteDomain);
    }

    public function restored(SiteDomain $siteDomain): void
    {
        $this->flushSiteCaches($siteDomain);
    }

    private function flushSiteCaches(SiteDomain $siteDomain): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::Site,
            CacheEnum::SiteLanguages,
            CacheEnum::LanguageByIdOrSite,
            CacheEnum::RelationExists,
        ]);

        event(new FrontendSurrogateKeysInvalidated(['site-' . $siteDomain->site_id]));
    }
}
