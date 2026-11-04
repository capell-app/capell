<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Who owns a route, recorded in its action when admin code registers it. The action travels with the
 * route through `route:cache`, so ownership never has to be inferred from a route's code. A route
 * with no recorded provenance that no panel names, declares or holds under its path is a public host route.
 */
final class AdminRouteProvenance
{
    /** The route action attribute that records the provenance. */
    public const string ACTION = 'capell_admin';

    /** Admin code outside every panel path: owned by the default Capell panel and held to its whole stack. */
    public const string ADMIN = 'admin';

    /** Reachable without signing in, because something other than a session is its credential (a URL signature or token). */
    public const string PUBLIC = 'public';

    /** Register admin routes, recording on each that admin code owns it. */
    public static function group(Closure $routes): void
    {
        RouteFacade::group([self::ACTION => self::ADMIN], $routes);
    }

    /** The provenance recorded on the route, if any. */
    public static function of(Route $route): ?string
    {
        // Nested groups merge their attributes recursively, so a nested declaration arrives as a list.
        $declared = (array) $route->getAction(self::ACTION);
        if (in_array(self::ADMIN, $declared, true)) {
            return self::ADMIN;
        }

        return in_array(self::PUBLIC, $declared, true) ? self::PUBLIC : null;
    }
}
