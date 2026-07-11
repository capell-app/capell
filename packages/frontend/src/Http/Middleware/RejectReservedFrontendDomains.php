<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Frontend\Support\Routing\ReservedFrontendDomainRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts any frontend request whose host has been reserved (e.g. the Filament
 * admin domain). Runs before path-based defences because a reserved host must
 * be excluded regardless of the request path.
 */
final class RejectReservedFrontendDomains
{
    public function __construct(
        private readonly ReservedFrontendDomainRegistry $reservedDomains,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->reservedDomains->isReserved($request->getHost()), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
