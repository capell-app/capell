<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\Install\AdminInstallState;
use Capell\Admin\Support\PaletteRequestState;
use Capell\Admin\Support\SitePermissionScopeResolver;
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
 *  1. Resolved content-lock record or a bound route model with a site_id
 *  2. ?site / ?site_id query parameter, restricted to assigned sites
 *  3. Session-selected site
 *  4. Current user's default site (single assigned site) — fallback only
 *
 * Global admins (team_id = NULL) are unaffected — their permissions already
 * span all sites and no scoping is applied.
 *
 * Register this middleware on the Filament panel in AdminPanelProvider:
 *
 *   ->middleware([SetSitePermissionScope::class], isPersistent: true)
 */
class SetSitePermissionScope
{
    public function __construct(private readonly AdminInstallState $installState) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Filament runs persistent middleware before the installer redirect, and an
        // uninstalled site has no site or permission tables to scope against.
        if (! $this->installState->installed()) {
            return $next($request);
        }

        $user = $request->user();

        resolve(SitePermissionScopeResolver::class)->syncSessionSite($request, $user);

        $siteId = $this->permissionTeamId($request);
        if ($user instanceof Authenticatable) {
            resolve(PaletteRequestState::class)->recordTeamId($user, $siteId);
        }

        return PermissionTeamContext::run(
            $siteId,
            fn (): Response => $next($request),
            $user instanceof Model ? $user : null,
        );
    }

    public function permissionTeamId(Request $request): ?int
    {
        return resolve(SitePermissionScopeResolver::class)->permissionTeamId($request);
    }
}
