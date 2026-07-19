<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Support\Url\UrlPathNormalizer;
use Capell\Frontend\Data\FrontendWork;
use Closure;

final class NormalizeDomainPathStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $state = $work->state;
        $domain = $state->domain();
        $path = $state->effectiveUrl() ?? ($state->relativePath() ?? '/');

        // Ensure leading slash
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        // Strip domain path prefix if present (e.g., /en)
        $prefix = $domain?->path ?? null;
        if (is_string($prefix) && $prefix !== '') {
            $path = UrlPathNormalizer::stripPrefix($path, $prefix);
        }

        $path = UrlPathNormalizer::stripIndexPhp($path);

        $state->setEffectiveUrl($path);

        return $next($work);
    }
}
