<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
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

        if ($this->hasPermission($user, 'view_any')) {
            return true;
        }

        return $this->hasPermission($user, 'view');
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

        return $this->hasPermission($user, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return ! $this->isOwnRecord($user, $record)
            && $this->hasPermission($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'delete_any');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'restore');
    }

    public function restoreAny(User $user): bool
    {
        return $this->hasPermission($user, 'restore_any');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return ! $this->isOwnRecord($user, $record)
            && $this->hasPermission($user, 'force_delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->hasPermission($user, 'force_delete_any');
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->hasPermission($user, 'replicate');
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
}
