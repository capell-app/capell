<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

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

        // Normalize index.php to root
        if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
            $path = '/';
        }

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
