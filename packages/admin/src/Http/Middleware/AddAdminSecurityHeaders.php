<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddAdminSecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config('capell-admin.security_headers.enabled', true) !== true) {
            return $response;
        }

        $headers = config('capell-admin.security_headers.headers', []);

        if (! is_array($headers)) {
            return $response;
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if ($name === '') {
                continue;
            }

            if ($value === '') {
                continue;
            }

            if ($response->headers->has($name)) {
                continue;
            }

            $response->headers->set($name, $value);
        }

        return $response;
    }
}
