<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Core\Models\Page;
use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Filament-compatible policy for Page / page-variation resources.
 *
 * Site scoping is handled transparently by Spatie's team feature: the
 * SetSitePermissionScope middleware sets the active team ID before any
 * request reaches a policy, so checkPermissionTo() calls are already scoped.
 *
 * Page-level role restrictions (page_role_restrictions table) add a second
 * layer: even if a user can edit pages on a site, they must also hold a
 * matching role to access a restricted page.
 *
 * Super-admins (global role with team_id = NULL) bypass all checks.
 *
 * Permission names are built via Filament Shield so they match the host
 * app's `config/filament-shield.php` (case + separator). See the
 * ResolvesShieldPermission trait.
 */
class PagePolicy
{
    use ResolvesShieldPermission;

    public function viewAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if ($user->checkPermissionTo(self::permission('view_any', $this->subject()))) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('view', $this->subject()));
    }

    public function view(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (
            ! $user->checkPermissionTo(self::permission('view_any', $this->subject()))
            && ! $user->checkPermissionTo(self::permission('view', $this->subject()))
        ) {
            return false;
        }

        return $page->isAccessibleByUser($user);
    }

    public function create(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('create', $this->subject()));
    }

    public function export(User $user, ?Page $page = null): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->checkPermissionTo(CapellPermission::ExportPage->name())) {
            return false;
        }

        return ! $page instanceof Page || $page->isAccessibleByUser($user);
    }

    public function update(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->checkPermissionTo(self::permission('update', $this->subject()))) {
            return false;
        }

        return $page->isAccessibleByUser($user);
    }

    public function editContent(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (
            ! $this->hasPermission($user, self::permission('edit_content', $this->subject()))
            && ! $user->checkPermissionTo(self::permission('update', $this->subject()))
        ) {
            return false;
        }

        return $page->isAccessibleByUser($user);
    }

    public function editLayout(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (
            ! $this->hasPermission($user, self::permission('edit_layout', $this->subject()))
            && ! $user->checkPermissionTo(self::permission('update', $this->subject()))
        ) {
            return false;
        }

        return $page->isAccessibleByUser($user);
    }

    public function delete(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $user->checkPermissionTo(self::permission('delete', $this->subject()))) {
            return false;
        }

        return $page->isAccessibleByUser($user);
    }

    public function deleteAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('delete_any', $this->subject()));
    }

    public function restore(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('restore', $this->subject()))
            && $page->isAccessibleByUser($user);
    }

    public function restoreAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('restore_any', $this->subject()));
    }

    public function forceDelete(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('force_delete', $this->subject()))
            && $page->isAccessibleByUser($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('force_delete_any', $this->subject()));
    }

    public function replicate(User $user, Page $page): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('replicate', $this->subject()))
            && $page->isAccessibleByUser($user);
    }

    public function reorder(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('reorder', $this->subject()));
    }

    /**
     * Manage page-level role restrictions — super-admin / site-admin only.
     * Register the resolved permission name (e.g. "ManageRestrictions:Page") in
     * the host app's `filament-shield.php` custom_permissions list.
     */
    public function manageRestrictions(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        return $user->checkPermissionTo(CapellPermission::ManagePageRestrictions->name());
    }

    private function subject(): string
    {
        return 'Page';
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->checkPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
