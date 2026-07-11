<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Illuminate\Http\Request;

/**
 * Single predicate answering whether a request targets a host or path that the
 * Capell public frontend — including its themed error pages — must never
 * handle. The Filament admin panel is the primary case (by path such as
 * "admin/..." or by a dedicated admin host), plus any operator- or
 * package-registered excludes.
 *
 * It combines the reserved-domain and reserved-path registries so every
 * consumer (route guards and, crucially, the app exception handler that renders
 * Capell error pages) shares one extensible check instead of re-implementing
 * admin detection. Both registries are seeded from config and can be extended
 * at runtime, so new excludes apply everywhere this predicate is used.
 */
final class ReservedFrontendRequest
{
    public function __construct(
        private readonly ReservedFrontendDomainRegistry $reservedDomains,
        private readonly ReservedFrontendPathRegistry $reservedPaths,
    ) {}

    public function matches(Request $request): bool
    {
        if ($this->reservedDomains->isReserved($request->getHost())) {
            return true;
        }

        return $this->reservedPaths->isReserved($request->path());
    }
}
