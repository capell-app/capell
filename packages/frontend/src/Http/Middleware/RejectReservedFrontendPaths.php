<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectReservedFrontendPaths
{
    /** @var list<string> */
    private const array INTERNAL_ROUTE_NAMES = [
        'capell-layout-builder.layout-widgets.show',
        'capell-frontend.fragments.show',
    ];

    public function __construct(
        private readonly ReservedFrontendPathRegistry $reservedPaths,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExplicitInternalRoute($request)) {
            return $next($request);
        }

        abort_if($this->reservedPaths->isReserved($request->path()), Response::HTTP_NOT_FOUND);

        return $next($request);
    }

    private function isExplicitInternalRoute(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        $name = method_exists($route, 'getName') ? $route->getName() : null;

        return is_string($name) && in_array($name, self::INTERNAL_ROUTE_NAMES, true);
    }
}
