<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\SiteScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

class UserPolicy
{
    use ResolvesShieldPermission;

    private const string SUBJECT = 'User';

    public function viewAny(User $user): bool
    {
        if ($this->hasPermission($user, 'view_any')) {
            return true;
        }

        return $this->hasPermission($user, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        if ($this->isOwnRecord($user, $record)) {
            return true;
        }

        return ($this->hasPermission($user, 'view_any') || $this->hasPermission($user, 'view'))
            && $this->canAccessRecordSite($user, $record);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        if ($this->isOwnRecord($user, $record)) {
            return true;
        }

        return $this->hasPermission($user, 'update') && $this->canAccessRecordSite($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return ! $this->isOwnRecord($user, $record)
            && $this->hasPermission($user, 'delete')
            && $this->canAccessRecordSite($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'delete_any');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'restore') && $this->canAccessRecordSite($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->hasPermission($user, 'restore_any');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return ! $this->isOwnRecord($user, $record)
            && $this->hasPermission($user, 'force_delete')
            && $this->canAccessRecordSite($user, $record);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'force_delete_any');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'replicate') && $this->canAccessRecordSite($user, $record);
    }

    public function reorder(User $user): bool
    {
        return $this->hasPermission($user, 'reorder');
    }

    private function hasPermission(User $user, string $affix): bool
    {
        return $user->checkPermissionTo(self::permission($affix, self::SUBJECT));
    }

    private function isOwnRecord(User $user, Model $record): bool
    {
        return (string) $user->getKey() === (string) $record->getKey();
    }

    private function canAccessRecordSite(User $user, Model $record): bool
    {
        if (SiteScope::isGlobalActor($user)) {
            return true;
        }

        if (! method_exists($record, 'getAssignedSiteIds')) {
            return false;
        }

        return $user->getAssignedSiteIds()->intersect($record->getAssignedSiteIds())->isNotEmpty();
    }
}
