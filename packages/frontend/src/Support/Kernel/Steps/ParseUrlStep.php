<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Support\Url\UrlPathNormalizer;
use Capell\Frontend\Data\FrontendWork;
use Closure;

final class ParseUrlStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $request = $work->request;
        $fullUrl = $request->fullUrl();

        $path = $request->getPathInfo() ?? '/';
        $request->server->get('QUERY_STRING') ?? '';

        $path = UrlPathNormalizer::stripIndexPhp($path);

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (str_ends_with($fullUrl, '/') && ! str_ends_with($path, '/')) {
            $path .= '/';
        }

        $work->state->setEffectiveUrl($path);

        return $next($work);
    }
}
