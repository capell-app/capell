<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Middleware a package appends to the web group after the panel has booted, without a router priority. */
final class LateWebGroupMarkerMiddleware
{
    public const string HEADER = 'X-Late-Web-Group-Marker';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set(self::HEADER, 'ran');

        return $response;
    }
}
