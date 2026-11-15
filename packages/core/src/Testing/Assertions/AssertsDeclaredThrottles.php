<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Holds a package's `security.throttles` declaration to what its booted provider registers.
 *
 * For every declared purpose: a named rate limiter exists, its first limit matches the declared
 * attempts and window, and each declared route exists and runs `throttle:<purpose>`. Every public
 * route the manifest names must be throttled by a declared purpose, except webhook routes, which
 * `Route::capellWebhook()` throttles itself. Packages that declare no throttles are not checked.
 */
final class AssertsDeclaredThrottles
{
    private const string CONTRACT_ID = 'security.throttles';

    public static function run(string $manifestPath): void
    {
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        $security = is_array($manifest) && is_array($manifest['security'] ?? null) ? $manifest['security'] : [];
        $throttles = $security['throttles'] ?? null;

        if (! is_array($throttles) || $throttles === []) {
            return;
        }

        $router = resolve(Router::class);
        $guardedBy = [];

        foreach ($throttles as $purpose => $throttle) {
            $purpose = (string) $purpose;
            $throttle = is_array($throttle) ? $throttle : [];
            self::assertLimiterMatches($manifestPath, $purpose, $throttle);

            foreach ((array) ($throttle['routes'] ?? []) as $routeName) {
                $route = $router->getRoutes()->getByName((string) $routeName);

                if (! $route instanceof Route) {
                    self::fail($manifestPath, sprintf('purpose [%s] declares route [%s], which is not registered.', $purpose, $routeName));
                }

                if (! in_array($purpose, self::throttlePurposes($router, $route), true)) {
                    self::fail($manifestPath, sprintf('route [%s] is declared under [%s] but does not run throttle:%s.', $routeName, $purpose, $purpose));
                }

                $guardedBy[(string) $routeName] = $purpose;
            }
        }

        $publicSurface = is_array($security['publicSurface'] ?? null) ? $security['publicSurface'] : [];
        $webhookRoutes = array_map(strval(...), (array) ($publicSurface['webhookRoutes'] ?? []));

        foreach ((array) ($publicSurface['routeNames'] ?? []) as $routeName) {
            $routeName = (string) $routeName;
            if (in_array($routeName, $webhookRoutes, true)) {
                continue;
            }

            if (array_key_exists($routeName, $guardedBy)) {
                continue;
            }

            $route = $router->getRoutes()->getByName($routeName);
            $undeclared = $route instanceof Route ? array_diff(self::throttlePurposes($router, $route), array_keys($throttles)) : [];

            self::fail($manifestPath, $undeclared === []
                ? sprintf('public route [%s] is not guarded by any declared security.throttles purpose.', $routeName)
                : sprintf('public route [%s] runs throttle:%s, which security.throttles does not declare.', $routeName, implode(', throttle:', $undeclared)));
        }
    }

    /** @param  array<string, mixed>  $throttle */
    private static function assertLimiterMatches(string $manifestPath, string $purpose, array $throttle): void
    {
        $limiter = RateLimiter::limiter($purpose);

        if ($limiter === null) {
            self::fail($manifestPath, sprintf('purpose [%s] is declared but no rate limiter is registered for it.', $purpose));
        }

        $limits = $limiter(Request::create('/'));
        $limit = is_array($limits) ? ($limits[0] ?? null) : $limits;

        if (! $limit instanceof Limit) {
            self::fail($manifestPath, sprintf('purpose [%s] does not resolve to a rate limit.', $purpose));
        }

        if ($limit->maxAttempts !== ($throttle['maxAttempts'] ?? null) || $limit->decaySeconds !== ($throttle['decaySeconds'] ?? null)) {
            self::fail($manifestPath, sprintf(
                'purpose [%s] declares %s attempts per %s seconds but registers %d per %d.',
                $purpose,
                var_export($throttle['maxAttempts'] ?? null, true),
                var_export($throttle['decaySeconds'] ?? null, true),
                $limit->maxAttempts,
                $limit->decaySeconds,
            ));
        }
    }

    /** @return list<string> the named limiters the route's middleware stack throttles with */
    private static function throttlePurposes(Router $router, Route $route): array
    {
        $purposes = [];

        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            $parameters = Str::after((string) $middleware, ':');
            if ($parameters === (string) $middleware) {
                continue;
            }

            if (! Str::contains((string) $middleware, 'ThrottleRequests')) {
                continue;
            }

            $purposes[] = Str::before($parameters, ',');
        }

        return $purposes;
    }

    private static function fail(string $manifestPath, string $message): never
    {
        throw new AssertionError(sprintf('[%s] %s: %s', self::CONTRACT_ID, $manifestPath, $message));
    }
}
