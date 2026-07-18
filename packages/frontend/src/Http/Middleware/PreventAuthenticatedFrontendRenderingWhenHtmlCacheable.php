<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\Loader\PageCachePolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventAuthenticatedFrontendRenderingWhenHtmlCacheable
{
    public function __construct(private readonly FrontendContextReader $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRenderAnonymously($request)) {
            $request->setUserResolver(fn (): null => null);
        }

        return $next($request);
    }

    private function shouldRenderAnonymously(Request $request): bool
    {
        if (config('capell-frontend.html_cache', true) !== true) {
            return false;
        }

        if ($request->query->has('without_html_cache')) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->headers->has('Authorization')) {
            return false;
        }

        return PageCachePolicy::shouldCache($this->context->page());
    }
}
