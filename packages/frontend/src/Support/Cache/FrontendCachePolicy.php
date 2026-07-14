<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Frontend\Contracts\FrontendContextReader;
use Illuminate\Http\Request;

final class FrontendCachePolicy
{
    public function shouldCache(FrontendContextReader $context, Request $request): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }

        if ($context->isError()) {
            return false;
        }

        // Skip cache for signed preview requests
        if (is_string($request->query('signature')) && $request->query('signature') !== '') {
            return false;
        }

        if (is_string($request->query('__theme_preview')) && $request->query('__theme_preview') !== '') {
            return false;
        }

        // Skip cache for authenticated users
        if (config('capell-frontend.cache_skip_authenticated', true) === true && $request->user() !== null) {
            return false;
        }

        // Skip cache when session cookie present (personalized content likelihood)
        $sessionName = config('session.cookie');
        $hasSessionName = is_string($sessionName) && $sessionName !== '';

        // Preserve existing behavior: rely on current headers/middleware
        return ! $hasSessionName || ! $request->cookies->has($sessionName);
    }
}
