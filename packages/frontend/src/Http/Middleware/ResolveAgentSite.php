<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteAccessContextData;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteAccess\SiteAccessPolicyRegistry;
use Capell\Frontend\Support\Loader\SiteLoader;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveAgentSite
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('capell.agent.read_api', true), 404);
        $resolution = ResolveSiteDomainAction::run(
            SiteRequestTargetData::fromUrl($request->getSchemeAndHttpHost() . '/'),
            SiteLoader::getSites(),
        );
        $domain = $resolution?->siteDomain;
        abort_unless($domain instanceof SiteDomain && $domain->status, 404);
        $policy = resolve(SiteAccessPolicyRegistry::class)->resolve(new SiteAccessContextData(
            request: $request,
            site: $domain->site,
            siteDomain: $domain,
        ));
        abort_if($policy?->active === true || $policy?->configurationAvailable === false, 404);
        $request->attributes->set('capell.agent.domain', $domain);

        $response = $next($request);
        $response->headers->set('X-Capell-Agent-Schema', '1');
        $response->headers->set('Cache-Control', $response->isSuccessful() ? 'public, max-age=60' : 'no-store');

        return $response;
    }
}
