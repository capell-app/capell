<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

final class RenderFallbackPublicViewAction
{
    use AsObject;

    public function handle(Request $request): ?Response
    {
        $pathView = trim($request->path(), '/') === ''
            ? 'home'
            : str_replace('/', '.', trim($request->path(), '/'));

        if ($pathView !== '' && $this->isAllowedFallbackViewName($pathView) && View::exists($pathView)) {
            return $this->guardPublicHtmlResponse(response()->view($pathView));
        }

        $fallbackView = $request->route()?->getName();

        if (is_string($fallbackView) && View::exists($fallbackView)) {
            return $this->guardPublicHtmlResponse(response()->view($fallbackView));
        }

        return null;
    }

    /**
     * Determine whether a request-derived Blade view name may be rendered by the
     * public path fallback.
     *
     * The request path is attacker-influenced, so mapping it straight to a view
     * name lets an anonymous visitor coerce arbitrary nested app views into
     * rendering (e.g. /admin/users -> "admin.users"). This constrains the name to
     * an explicit allowlist and always rejects namespaced names, path traversal
     * and control characters.
     */
    private function isAllowedFallbackViewName(string $viewName): bool
    {
        // Reject namespaced views ("namespace::view"), traversal and control
        // characters outright. A path-derived name should never contain these.
        if (
            str_contains($viewName, '::')
            || str_contains($viewName, '..')
            || preg_match('/[\x00-\x1f]/', $viewName) === 1
        ) {
            return false;
        }

        $segments = explode('.', $viewName);

        foreach ($segments as $segment) {
            if ($segment === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return false;
            }
        }

        $allowlist = $this->fallbackAllowlist();

        // Explicitly permitted full view names always pass.
        if (in_array($viewName, $allowlist['view_names'], true)) {
            return true;
        }

        // Single-segment, author-authored top-level views remain permitted.
        if (count($segments) === 1) {
            return true;
        }

        // Multi-segment names are only permitted when their leading segment is an
        // allowlisted prefix reserved for publicly renderable templates.
        return in_array($segments[0], $allowlist['prefixes'], true);
    }

    /**
     * @return array{view_names: list<string>, prefixes: list<string>}
     */
    private function fallbackAllowlist(): array
    {
        $configured = config('capell-frontend.fallback_public_views', []);

        $viewNames = is_array($configured) && isset($configured['view_names']) && is_array($configured['view_names'])
            ? array_values(array_filter($configured['view_names'], is_string(...)))
            : [];

        $prefixes = is_array($configured) && isset($configured['prefixes']) && is_array($configured['prefixes'])
            ? array_values(array_filter($configured['prefixes'], is_string(...)))
            : ['pages'];

        return [
            'view_names' => $viewNames,
            'prefixes' => $prefixes,
        ];
    }

    private function guardPublicHtmlResponse(Response $response): Response
    {
        AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response);

        return $response;
    }
}
