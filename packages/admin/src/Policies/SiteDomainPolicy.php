<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Foundation\Auth\User;

/**
 * Domains are managed while editing their owning Site. Adding, editing and deleting
 * domains therefore use the Site update permission (including UpdateOwn:Site),
 * together with access to that Site. Creating or deleting a Site is not required.
 * Restore and permanent deletion continue to use the corresponding Site permissions.
 */
final class SiteDomainPolicy
{
    use ResolvesShieldPermission;

    public function __construct(private readonly SitePolicy $sites) {}

    public function viewAny(User $user): bool
    {
        return $this->sites->viewAny($user);
    }

    public function view(User $user, SiteDomain $domain): bool
    {
        $site = $this->site($domain);

        return $site instanceof Site && SiteAccess::forActor($user)->can($site) && $this->sites->view($user, $site);
    }

    public function create(User $user, ?Site $site = null): bool
    {
        if ($site instanceof Site) {
            return SiteAccess::forActor($user)->can($site) && $this->sites->update($user, $site);
        }

        foreach (SiteAccess::forActor($user)->query(Site::class)->get() as $accessibleSite) {
            if ($this->sites->update($user, $accessibleSite)) {
                return true;
            }
        }

        return false;
    }

    public function update(User $user, SiteDomain $domain): bool
    {
        $site = $this->site($domain);

        return $site instanceof Site && SiteAccess::forActor($user)->can($site) && $this->sites->update($user, $site);
    }

    public function delete(User $user, SiteDomain $domain): bool
    {
        $site = $this->site($domain);

        return $site instanceof Site && SiteAccess::forActor($user)->can($site) && $this->sites->update($user, $site);
    }

    public function deleteAny(User $user, ?Site $site = null): bool
    {
        return $this->create($user, $site);
    }

    public function restore(User $user, SiteDomain $domain): bool
    {
        $site = $this->site($domain);

        return $site instanceof Site && SiteAccess::forActor($user)->can($site) && $this->sites->restore($user, $site);
    }

    public function forceDelete(User $user, SiteDomain $domain): bool
    {
        $site = $this->site($domain);

        return $site instanceof Site && SiteAccess::forActor($user)->can($site) && $this->sites->forceDelete($user, $site);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', 'Site'));
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', 'Site'));
    }

    private function site(SiteDomain $domain): ?Site
    {
        return Site::withTrashed()->find($domain->site_id);
    }
}
