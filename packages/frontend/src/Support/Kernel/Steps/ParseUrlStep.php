<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Frontend\Data\FrontendWork;
use Closure;

/**
 * @deprecated SiteResolveStep owns canonical request path resolution. This no-op compatibility step will be removed in 2.0.
 */
final class ParseUrlStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        return $next($work);
    }
}
