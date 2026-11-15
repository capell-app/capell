<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware an application appends to the web group without giving it a router priority, as Laravel Boost does. */
final class WebGroupMarkerMiddleware
{
    public const string HEADER = 'X-Web-Group-Marker';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set(self::HEADER, 'ran');

        return $response;
    }
}
