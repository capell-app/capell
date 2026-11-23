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

        // Only what the response does not already carry, so a host application (or a route,
        // or an inner middleware) that sends its own value keeps it and nothing is duplicated.
        // No Content-Security-Policy is sent: X-Frame-Options covers framing, and an enforced
        // policy is the host's decision.
        $this->setIfMissing($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfMissing($response, 'X-Frame-Options', 'SAMEORIGIN');
        $this->setIfMissing($response, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->setIfMissing($response, 'Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $this->setIfMissing($response, 'X-Permitted-Cross-Domain-Policies', 'none');

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

            $this->setIfMissing($response, 'Strict-Transport-Security', $header);
        }

        return $response;
    }

    private function setIfMissing(Response $response, string $name, string $value): void
    {
        if (! $response->headers->has($name)) {
            $response->headers->set($name, $value);
        }
    }
}
