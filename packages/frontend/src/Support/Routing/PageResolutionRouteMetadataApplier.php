<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Frontend\Data\PageResolutionData;
use Illuminate\Http\Request;

final class PageResolutionRouteMetadataApplier
{
    public function apply(Request $request, PageResolutionData $resolution): void
    {
        $route = $request->route();

        if ($resolution->pageQuery !== null) {
            $request->merge(['pageQuery' => $resolution->pageQuery]);
            $request->query->set('pageQuery', $resolution->pageQuery);
            $request->attributes->set('pageQuery', $resolution->pageQuery);

            if ($route !== null) {
                if ($resolution->routeUri !== null) {
                    $route->setUri($resolution->routeUri);
                }

                $route->setParameter('page', $resolution->pageQuery);
                $route->setParameter('pageQuery', $resolution->pageQuery);
                $route->setParameter('pageQueryParams', $resolution->params);
            }
        }

        if ($resolution->slug !== null && $route !== null) {
            $route->setParameter('pageSlug', $resolution->slug);
        }
    }
}
