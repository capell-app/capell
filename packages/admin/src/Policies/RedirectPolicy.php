<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

/**
 * Authorises page URL records by type. Redirects use the redirect permissions;
 * a page's own (non-redirect) URLs follow the policy of the record that owns them,
 * so redirect editors cannot change, delete or restore canonical page URLs.
 */
class RedirectPolicy
{
    use ResolvesShieldPermission;

    public function viewAny(User $user): bool
    {
        if ($user->checkPermissionTo(self::permission('view_any', $this->subject()))) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('view', $this->subject()));
    }

    public function view(User $user, PageUrl $record): bool
    {
        $ownerDecision = $this->ownerAllows($user, $record, 'view');

        if ($ownerDecision !== null) {
            return $ownerDecision;
        }

        if ($user->checkPermissionTo(self::permission('view_any', $this->subject()))) {
            return $this->canAccessRecord($user, $record);
        }

        return $user->checkPermissionTo(self::permission('view', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', $this->subject()));
    }

    public function update(User $user, PageUrl $record): bool
    {
        $ownerDecision = $this->ownerAllows($user, $record, 'update');

        if ($ownerDecision !== null) {
            return $ownerDecision;
        }

        return $user->checkPermissionTo(self::permission('update', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function delete(User $user, PageUrl $record): bool
    {
        $ownerDecision = $this->ownerAllows($user, $record, 'update');

        if ($ownerDecision !== null) {
            return $ownerDecision;
        }

        return $user->checkPermissionTo(self::permission('delete', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function replicate(User $user, PageUrl $record): bool
    {
        $canReplicate = $user->checkPermissionTo(self::permission('replicate', $this->subject()))
            && $this->canAccessRecord($user, $record);
        $ownerDecision = $this->ownerAllows($user, $record, 'update');

        // A copy of a page's own URL becomes another URL for that page, so it needs both
        // the replicate permission and the right to edit the page.
        if ($ownerDecision !== null) {
            return $canReplicate && $ownerDecision;
        }

        return $canReplicate;
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', $this->subject()));
    }

    public function restore(User $user, PageUrl $record): bool
    {
        $ownerDecision = $this->ownerAllows($user, $record, 'update');

        if ($ownerDecision !== null) {
            return $ownerDecision;
        }

        return $user->checkPermissionTo(self::permission('restore', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', $this->subject()));
    }

    public function forceDelete(User $user, PageUrl $record): bool
    {
        $ownerDecision = $this->ownerAllows($user, $record, 'update');

        if ($ownerDecision !== null) {
            return $ownerDecision;
        }

        return $user->checkPermissionTo(self::permission('force_delete', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', $this->subject()));
    }

    public function import(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('import', $this->subject()));
    }

    public function export(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('export', $this->subject()));
    }

    private function subject(): string
    {
        return 'PageUrl';
    }

    /**
     * Decides a page's own (non-redirect) URL through the policy of the record that owns it.
     * Returns null for redirects and for URLs with no owner reference, which stay under the
     * redirect permissions. An owner that is referenced but cannot be loaded (for example a
     * deleted page) is refused rather than falling back to the redirect permissions.
     */
    private function ownerAllows(User $user, PageUrl $record, string $ability): ?bool
    {
        if ($record->isRedirect() || $record->pageable_type === null || $record->pageable_id === null) {
            return null;
        }

        $record->loadMissing('pageable');
        $owner = $record->pageable;

        if (! $owner instanceof Model) {
            return false;
        }

        return Gate::forUser($user)->check($ability, $owner);
    }

    private function canAccessRecord(User $user, PageUrl $record): bool
    {
        $record->loadMissing('site');

        return SiteAccess::forActor($user)->can($record->site);
    }
}
