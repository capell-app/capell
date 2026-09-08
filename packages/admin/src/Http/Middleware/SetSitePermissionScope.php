<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active site from the current request and sets Spatie's team ID
 * accordingly, so that all subsequent hasPermissionTo() / hasRole() calls are
 * automatically scoped to that site.
 *
 * Resolution order:
 *  1. ?site / ?site_id query parameter (Filament resource pages often pass this)
 *  2. Route model binding — {record} resolved to a model that has a site_id
 *  3. Session-selected site
 *  4. Current user's default site (single assigned site) — fallback only
 *
 * Global admins (team_id = NULL) are unaffected — their permissions already
 * span all sites and no scoping is applied.
 *
 * Register this middleware on the Filament panel in AdminPanelProvider:
 *
 *   ->middleware([SetSitePermissionScope::class])
 */
class SetSitePermissionScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->syncSessionSite($request, $user);

        $siteId = $user instanceof Authenticatable && SiteScope::isGlobalActor($user)
            ? null
            : $this->resolveSiteId($request);

        return PermissionTeamContext::run(
            $siteId,
            fn (): Response => $next($request),
            $user instanceof Model ? $user : null,
        );
    }

    private function resolveSiteId(Request $request): ?int
    {
        // 2. Route model has a site_id attribute (Page, Layout, Navigation, etc.)
        //    Checked FIRST — the record itself is authoritative and cannot be
        //    spoofed by the caller.
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $siteId = $parameter->getAttribute('site_id');
                if ($siteId !== null) {
                    return (int) $siteId;
                }
            }

            if ($parameter instanceof Site) {
                return (int) $parameter->getKey();
            }
        }

        // 1. Explicit query / form parameter — only honoured when the user
        //    actually has a role on that site. This prevents `?site_id=<other>`
        //    from widening the Spatie team scope.
        $requestedSiteId = $this->requestedSiteId($request);

        if ($requestedSiteId !== null) {
            $user = $request->user();
            $site = Site::query()->find($requestedSiteId);

            if ($site !== null && $this->actorBelongsToSite($user, $site)) {
                return $requestedSiteId;
            }

            return null;
        }

        // 3. Session-selected site — persisted by the admin topbar switcher.
        $sessionSiteId = (int) $request->session()->get('capell.current_site_id', 0);
        $user = $request->user();

        if ($sessionSiteId > 0
            && $user !== null) {
            $site = Site::query()->find($sessionSiteId);

            if ($site !== null && $this->actorBelongsToSite($user, $site)) {
                return $sessionSiteId;
            }
        }

        // 4. User has exactly one site assignment — use it as the implicit scope
        $user = $request->user();

        if ($user !== null) {
            $siteIds = method_exists($user, 'getAllAssignedSiteIds')
                ? $user->getAllAssignedSiteIds()
                : $user->getAssignedSiteIds();

            if ($siteIds->count() === 1) {
                return (int) $siteIds->first();
            }
        }

        return null;
    }

    private function actorBelongsToSite(?Authenticatable $actor, Site $site): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        $siteIds = method_exists($actor, 'getAllAssignedSiteIds')
            ? $actor->getAllAssignedSiteIds()
            : $actor->getAssignedSiteIds();

        return $siteIds->contains($site->getKey());
    }

    private function requestedSiteId(Request $request): ?int
    {
        $siteId = $request->integer('site') ?: $request->integer('site_id');

        return $siteId > 0 ? $siteId : null;
    }

    private function syncSessionSite(Request $request, ?Authenticatable $user): void
    {
        $requestedSiteId = $this->requestedSiteId($request);

        if ($requestedSiteId === null) {
            return;
        }

        $site = Site::query()->find($requestedSiteId);

        if ($site !== null && $this->actorBelongsToSite($user, $site)) {
            $request->session()->put('capell.current_site_id', $requestedSiteId);
        }
    }
}
