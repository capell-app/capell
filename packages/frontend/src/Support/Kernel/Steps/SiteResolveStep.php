<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Exceptions\SiteDomainNotFoundException;
use Capell\Core\Exceptions\UrlSiteDomainNotFoundException;
// Used only by the @var docblock on $sites below; PHPStan resolves it from this import.
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Site\SiteDomainFinder;
use Capell\Core\Support\Url\UrlPathNormalizer;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Loader\SiteLoader;
use Capell\Frontend\Support\Loader\SiteResolver;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

final class SiteResolveStep
{
    public function __construct(private readonly FrontendLogger $logger) {}

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $request = $work->request;
        $state = $work->state;

        $fullUrl = $request->fullUrl();

        /** @var Collection<int,Site> $sites */
        $sites = SiteLoader::getSites();

        try {
            [$site, $language, $domain, $normalizedPath] = SiteResolver::resolve($fullUrl, $sites);

            $state->withSite($site)
                ->withLanguage($language)
                ->withDomain($domain)
                ->setEffectiveUrl($normalizedPath)
                ->withRelativePath($normalizedPath);
        } catch (UrlSiteDomainNotFoundException|SiteDomainNotFoundException $domainException) {
            $this->logger->warning('Frontend: SiteResolveStep domain not found, considering redirect', ['url' => $fullUrl]);

            // Only attempt redirect when explicitly enabled AND we have sites loaded
            // (the redirect target is selected from the in-memory $sites collection).
            $shouldRedirect = (bool) Config::get('capell-frontend.redirect_default_site', false)
                && $sites->isNotEmpty();

            if ($shouldRedirect) {
                // Attempt default site redirect if an enabled default domain exists.
                // Use the already-loaded $sites collection to avoid an extra DB query;
                // short-circuit on first match instead of materialising every domain.
                $defaultDomain = SiteDomainFinder::firstEnabledDefault($sites);

                if ($defaultDomain instanceof SiteDomain) {
                    // Build redirect target preserving path & raw query, including domain path prefix when present
                    $scheme = $defaultDomain->scheme ?? 'https';
                    $host = $defaultDomain->domain ?? $request->getHost();
                    $prefix = rtrim((string) $defaultDomain->path, '/');
                    $path = UrlPathNormalizer::stripIndexPhp($request->getPathInfo() ?? '/');

                    // Prefer raw QUERY_STRING from server to preserve duplicates
                    $rawQuery = (string) ($request->server->get('QUERY_STRING') ?? '');
                    if ($rawQuery === '') {
                        $rawQuery = (string) (parse_url($fullUrl, PHP_URL_QUERY) ?? '');
                    }

                    $target = $scheme . '://' . $host;
                    if ($prefix !== '') {
                        $target .= $prefix;
                    }

                    // Only append path if not root, to avoid trailing slash for homepage
                    if ($path !== '/') {
                        $target .= $path;
                    }

                    if ($rawQuery !== '') {
                        $target .= '?' . $rawQuery;
                    }

                    $work->setRedirect(new RedirectResponse($target));

                    // Short-circuit: do not run subsequent steps when redirecting
                    return $work;
                }

                // Redirect requested but no default domain; propagate original exception
                throw $domainException;
            }

            $throwOnNoSites = (bool) Config::get('capell-frontend.throw_on_no_sites', false);

            // Silently 404 unless the caller has explicitly asked to surface the exception.
            abort_if(
                $domainException instanceof SiteDomainNotFoundException && ! $throwOnNoSites,
                404,
            );

            // Redirect disabled; propagate original exception so pipeline halts
            throw $domainException;
        }

        return $next($work);
    }
}
