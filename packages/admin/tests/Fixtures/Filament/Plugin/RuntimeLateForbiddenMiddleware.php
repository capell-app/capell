<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Calls the next middleware, then replaces its answer with a refusal. */
final class RuntimeLateForbiddenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $next($request);

        return new Response('refused after the route answered', \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
    }
}
