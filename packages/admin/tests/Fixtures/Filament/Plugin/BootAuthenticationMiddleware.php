<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticated-request middleware an installed package adds while the panel boots. */
final class BootAuthenticationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
