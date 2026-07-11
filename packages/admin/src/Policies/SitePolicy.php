<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Core\Models\Site;
use Illuminate\Foundation\Auth\User;

/**
 * Policy for the Site resource.
 *
 * Site management (create / delete) is a global-admin operation.
 * Viewing a site record is allowed to any user who holds a role on that site.
 *
 * Permission names are built via Shield so they match the host app's
 * `config/filament-shield.php` (case + separator). `update_own_site` and
 * `manage_site_permissions` are custom permissions — register them in the
 * host app's `filament-shield.php` custom_permissions list.
 */
class SitePolicy
{
    use ResolvesShieldPermission;

    private const string SUBJECT = 'Site';

    public function viewAny(User $user): bool
    {
        // Global admins see all sites; site-scoped users see only their sites
        // (filtered at the Eloquent query level in SiteResource::getEloquentQuery)
        if ($user->checkPermissionTo(self::permission('view_any', self::SUBJECT))) {
            return true;
        }

        if ($user->getAssignedSiteIds()->isNotEmpty()) {
            return true;
        }

        return $user->isGlobalAdmin();
    }

    public function view(User $user, Site $site): bool
    {
        $isGlobal = $user->isGlobalAdmin();

        if ($isGlobal || $user->checkPermissionTo(self::permission('view_any', self::SUBJECT))) {
            return true;
        }

        return $user->getAssignedSiteIds()->contains($site->getKey());
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', self::SUBJECT));
    }

    public function export(User $user, ?Site $site = null): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->checkPermissionTo(CapellPermission::ExportSite->name())) {
            return false;
        }

        if (! $site instanceof Site) {
            return true;
        }

        return $user->getAssignedSiteIds()->contains($site->getKey());
    }

    public function update(User $user, Site $site): bool
    {
        if ($user->checkPermissionTo(self::permission('update', self::SUBJECT))) {
            return true;
        }

        // Site-admins can update their own site's settings (custom permission)
        return $user->checkPermissionTo(CapellPermission::UpdateOwnSite->name())
            && $user->getAssignedSiteIds()->contains($site->getKey());
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->checkPermissionTo(self::permission('delete', self::SUBJECT));
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', self::SUBJECT));
    }

    public function restore(User $user, Site $site): bool
    {
        return $user->checkPermissionTo(self::permission('restore', self::SUBJECT));
    }

    public function forceDelete(User $user, Site $site): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', self::SUBJECT));
    }

    /** Manage which users/roles are assigned to this site (custom permission). */
    public function managePermissions(User $user, Site $site): bool
    {
        return $user->checkPermissionTo(CapellPermission::ManageSitePermissions->name());
    }

    private function isSuperAdmin(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $user->hasRole(config('capell.roles.super_admin', 'super_admin'));
    }
}
