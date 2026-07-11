<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        $hsts = config('security.headers.hsts', []);

        if (($hsts['enabled'] ?? false) && $request->isSecure()) {
            $maxAge = (int) ($hsts['max_age'] ?? 31_536_000);
            $header = 'max-age=' . $maxAge;

            if ($hsts['include_subdomains'] ?? false) {
                $header .= '; includeSubDomains';
            }

            if ($hsts['preload'] ?? false) {
                $header .= '; preload';
            }

            $response->headers->set('Strict-Transport-Security', $header);
        }

        return $response;
    }
}
