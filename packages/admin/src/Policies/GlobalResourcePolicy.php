<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

/** Permissions for site-neutral resources use the same subjects and affixes as Shield. */
abstract class GlobalResourcePolicy
{
    use ResolvesShieldPermission;

    protected const string SUBJECT = '';

    public function viewAny(User $user): bool
    {
        if ($user->checkPermissionTo(self::permission('view_any', static::SUBJECT))) {
            return true;
        }

        return (bool) $user->checkPermissionTo(self::permission('view', static::SUBJECT));
    }

    public function view(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('view', static::SUBJECT));
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', static::SUBJECT));
    }

    public function update(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('update', static::SUBJECT));
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('delete', static::SUBJECT));
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', static::SUBJECT));
    }

    public function restore(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('restore', static::SUBJECT));
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', static::SUBJECT));
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', static::SUBJECT));
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', static::SUBJECT));
    }

    public function replicate(User $user, Model $record): bool
    {
        return $user->checkPermissionTo(self::permission('replicate', static::SUBJECT));
    }

    public function reorder(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('reorder', static::SUBJECT));
    }
}
