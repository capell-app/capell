<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Pagination;

use Capell\Frontend\Contracts\CacheBypassResolver;
use Illuminate\Http\Request;

/**
 * Decides whether public listing components should paginate and filter through
 * stateless, cacheable GET requests instead of stateful Livewire writes.
 *
 * The flag lives in the html-cache package config; this resolver reads it by
 * string so the frontend package keeps no class dependency on html-cache
 * (html-cache already depends on frontend). When the resolver reports inactive,
 * components must emit their legacy stateful markup unchanged.
 */
final class StatelessPaginationResolver
{
    public function __construct(private readonly CacheBypassResolver $cacheBypassResolver) {}

    /**
     * The operator-controlled config flag, independent of the current request.
     */
    public function isEnabled(): bool
    {
        return config('capell-html-cache.stateless_pagination.enabled', true) === true;
    }

    /**
     * Whether the current request is a public, cacheable, anonymous GET — i.e. one
     * that has no session/CSRF to back a Livewire write and so must not issue one.
     */
    public function isPublicCacheableRequest(?Request $request = null): bool
    {
        $request ??= request();

        if (! $request instanceof Request || ! $request->isMethod('GET')) {
            return false;
        }

        if ($request->headers->has('X-Livewire') || $request->headers->has('Authorization')) {
            return false;
        }

        if ($request->user() !== null) {
            return false;
        }

        $sessionCookie = config('session.cookie');

        if (is_string($sessionCookie) && $sessionCookie !== '' && $request->cookies->has($sessionCookie)) {
            return false;
        }

        return ! $this->cacheBypassResolver->shouldBypass();
    }

    /**
     * Both the flag and the request gate — the only thing components should check.
     */
    public function isActive(?Request $request = null): bool
    {
        return $this->isEnabled() && $this->isPublicCacheableRequest($request);
    }

    /**
     * The allow-listed query keys that may appear on a cacheable request without
     * vetoing the page cache.
     *
     * @return list<string>
     */
    public function allowedParams(): array
    {
        $params = config('capell-html-cache.stateless_pagination.params', []);

        return is_array($params) ? array_values(array_filter($params, is_string(...))) : [];
    }
}
