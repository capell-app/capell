<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Exceptions\UrlSiteDomainNotFoundException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Exception;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SiteResolver
{
    /**
     * Resolve the Site and Language from the full URL and available sites.
     * Returns [Site $site, Language $language, SiteDomain $siteDomain, string $normalizedUrl]
     *
     * @param  Collection<int,Site>  $sites
     * @return array{0:Site,1:Language,2:SiteDomain,3:string}
     */
    public static function resolve(string $fullUrl, Collection $sites): array
    {
        $logger = resolve(FrontendLogger::class);

        if ($sites->isEmpty()) {
            $logger->warning('Frontend: no sites configured, cannot resolve domain', ['url' => $fullUrl]);
            throw new SiteDomainNotFoundException('No sites are configured.');
        }

        try {
            $resolution = ResolveSiteDomainAction::run(
                SiteRequestTargetData::fromUrl($fullUrl),
                $sites,
            );
        } catch (InvalidArgumentException) {
            $resolution = null;
        }

        if ($resolution === null) {
            $logger->warning('Frontend: site domain not found for URL', ['url' => $fullUrl]);
            throw new UrlSiteDomainNotFoundException($fullUrl);
        }

        $siteDomain = $resolution->siteDomainForRequest();
        $url = $resolution->relativePath;

        $site = $sites->firstWhere('id', $siteDomain->site_id);
        if (! $site instanceof Site) {
            $logger->warning('Frontend: site record not found for resolved domain', [
                'url' => $fullUrl,
                'site_domain_id' => $siteDomain->id,
                'site_id' => $siteDomain->site_id,
            ]);
            throw new UrlSiteDomainNotFoundException($fullUrl);
        }

        $language = $siteDomain->language;

        if (! $language instanceof Language) {
            $logger->error('Frontend: no language found for resolved site domain', [
                'site_domain_id' => $siteDomain->id,
                'url' => $fullUrl,
            ]);

            throw new UrlSiteDomainNotFoundException($fullUrl);
        }

        $translation = $site->translations->firstWhere('language_id', $language->id);
        if ($translation === null) {
            $logger->error('Frontend: no translation found for site', [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'language_id' => $language->id,
                'url' => $fullUrl,
            ]);

            throw new Exception('No translation found for site: ' . $site->name);
        }

        $site->setRelation('translation', $translation);
        $site->setRelation('siteDomain', $siteDomain);

        $site = SiteLoader::loadSite($site, $language);

        if (! $site instanceof Site) {
            $logger->error('Frontend: site could not be loaded', [
                'site_domain_id' => $siteDomain->id,
                'url' => $fullUrl,
            ]);

            throw new UrlSiteDomainNotFoundException($fullUrl);
        }

        return [$site, $language, $siteDomain, $url];
    }
}
