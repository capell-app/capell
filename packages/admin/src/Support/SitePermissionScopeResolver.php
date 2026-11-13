<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Actions\Pages\ResolveContentLockRecordAction;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SitePermissionScopeResolver
{
    public function permissionTeamId(Request $request): ?int
    {
        $user = $request->user();

        return $user instanceof Authenticatable && SiteAccess::forActor($user)->isGlobal()
            ? null
            : $this->resolveSiteId($request);
    }

    public function syncSessionSite(Request $request, ?Authenticatable $user): void
    {
        $requestedSiteId = $this->requestedSiteId($request);

        if ($requestedSiteId === null) {
            return;
        }

        $site = SiteAccess::forActor($user, acrossAssignedSites: true)->query(Site::class)->find($requestedSiteId);

        if ($site !== null && $this->actorBelongsToSite($user, $site)) {
            $request->session()->put('capell.current_site_id', $requestedSiteId);
        }
    }

    private function resolveSiteId(Request $request): ?int
    {
        if ($request->routeIs('capell-admin.api.pages.content-lock.*')) {
            $recordId = $request->route('page');
            $type = $request->query('type', 'page');
            if (is_string($recordId) && is_string($type)) {
                $record = ResolveContentLockRecordAction::run($type, $recordId);
                if ($record !== null) {
                    return (int) $record->getAttribute('site_id');
                }
            }
        }

        // 2. Route model has a site_id attribute (Page, Layout, Navigation, etc.)
        //    Checked FIRST — the record itself is authoritative and cannot be
        //    spoofed by the caller.
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Site) {
                return (int) $parameter->getKey();
            }

            // A model without a site (a theme, say) says nothing about the scope.
            if ($parameter instanceof Model && $parameter->hasAttribute('site_id')) {
                $siteId = $parameter->getAttribute('site_id');
                if ($siteId !== null) {
                    return (int) $siteId;
                }
            }
        }

        // 1. Explicit query / form parameter — only honoured when the user
        //    actually has a role on that site. This prevents `?site_id=<other>`
        //    from widening the Spatie team scope.
        $requestedSiteId = $this->requestedSiteId($request);

        if ($requestedSiteId !== null) {
            $user = $request->user();
            $site = SiteAccess::forActor($user, acrossAssignedSites: true)->query(Site::class)->find($requestedSiteId);

            if ($site !== null && $this->actorBelongsToSite($user, $site)) {
                return $requestedSiteId;
            }

            return null;
        }

        // 3. Session-selected site — persisted by the admin topbar switcher.
        $sessionSiteId = $request->hasSession() ? (int) $request->session()->get('capell.current_site_id', 0) : 0;
        $user = $request->user();

        if ($sessionSiteId > 0
            && $user !== null) {
            $site = SiteAccess::forActor($user, acrossAssignedSites: true)->query(Site::class)->find($sessionSiteId);

            if ($site !== null && $this->actorBelongsToSite($user, $site)) {
                return $sessionSiteId;
            }
        }

        // 4. User has exactly one site assignment — use it as the implicit scope
        $user = $request->user();

        if ($user !== null) {
            $siteIds = $this->resolveAssignedSiteIds($user);

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

        if (SiteAccess::forActor($actor)->isGlobal()) {
            return true;
        }

        return $this->resolveAssignedSiteIds($actor)->contains($site->getKey());
    }

    /** @return Collection<int, int> */
    private function resolveAssignedSiteIds(Authenticatable $actor): Collection
    {
        if (method_exists($actor, 'getAllAssignedSiteIds')) {
            return $actor->getAllAssignedSiteIds();
        }

        if (method_exists($actor, 'getAssignedSiteIds')) {
            return $actor->getAssignedSiteIds();
        }

        return collect();
    }

    private function requestedSiteId(Request $request): ?int
    {
        $siteId = $request->integer('site') ?: $request->integer('site_id');

        return $siteId > 0 ? $siteId : null;
    }
}
