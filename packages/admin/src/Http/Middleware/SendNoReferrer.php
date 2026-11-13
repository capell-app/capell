<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps secrets in the URL (such as an invitation token) out of the Referer
 * header sent to any asset or link the page loads.
 */
final class SendNoReferrer
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
