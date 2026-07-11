<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Layout;
use Illuminate\Foundation\Auth\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Policy for the Layout resource.
 *
 * Layouts are site-scoped: a user may only interact with layouts that belong
 * to a site on which they hold a role. The active team ID set by
 * SetSitePermissionScope ensures checkPermissionTo() checks are already scoped.
 *
 * Permission names are built via Shield so they match the host app's
 * `config/filament-shield.php` (case + separator).
 */
class LayoutPolicy
{
    use ResolvesShieldPermission;

    public function viewAny(User $user): bool
    {
        if ($user->checkPermissionTo(self::permission('view_any', $this->subject()))) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('view', $this->subject()));
    }

    public function view(User $user, Layout $layout): bool
    {
        if (
            ! $user->checkPermissionTo(self::permission('view_any', $this->subject()))
            && ! $user->checkPermissionTo(self::permission('view', $this->subject()))
        ) {
            return false;
        }

        return $this->canUseLayoutSite($user, $layout);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', $this->subject()));
    }

    public function update(User $user, Layout $layout): bool
    {
        return $user->checkPermissionTo(self::permission('update', $this->subject()))
            && $this->canUseLayoutSite($user, $layout);
    }

    public function editContent(User $user, Layout $layout): bool
    {
        if ($this->hasPermission($user, self::permission('edit_content', $this->subject()))) {
            return true;
        }

        return $this->update($user, $layout);
    }

    public function editLayout(User $user, Layout $layout): bool
    {
        if ($this->hasPermission($user, self::permission('edit_layout', $this->subject()))) {
            return true;
        }

        return $this->update($user, $layout);
    }

    public function delete(User $user, Layout $layout): bool
    {
        return $user->checkPermissionTo(self::permission('delete', $this->subject()))
            && $this->canUseLayoutSite($user, $layout);
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', $this->subject()));
    }

    public function restore(User $user, Layout $layout): bool
    {
        return $user->checkPermissionTo(self::permission('restore', $this->subject()))
            && $this->canUseLayoutSite($user, $layout);
    }

    public function forceDelete(User $user, Layout $layout): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', $this->subject()))
            && $this->canUseLayoutSite($user, $layout);
    }

    public function replicate(User $user, Layout $layout): bool
    {
        return $user->checkPermissionTo(self::permission('replicate', $this->subject()))
            && $this->canUseLayoutSite($user, $layout);
    }

    public function reorder(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('reorder', $this->subject()));
    }

    private function subject(): string
    {
        return 'Layout';
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->checkPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function canUseLayoutSite(User $user, Layout $layout): bool
    {
        if ($layout->site_id === null || SiteScope::isGlobalActor($user)) {
            return true;
        }

        return $user->getAssignedSiteIds()->contains($layout->site_id);
    }
}
