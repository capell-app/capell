<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\MediaScope;
use Capell\Core\Models\Media;
use Illuminate\Foundation\Auth\User;

class MediaPolicy
{
    use ResolvesShieldPermission;

    private const string SUBJECT = 'Media';

    public function viewAny(User $user): bool
    {
        if ($user->checkPermissionTo(self::permission('view_any', self::SUBJECT))) {
            return true;
        }

        return $user->checkPermissionTo(self::permission('view', self::SUBJECT));
    }

    public function view(User $user, Media $media): bool
    {
        if (
            ! $user->checkPermissionTo(self::permission('view_any', self::SUBJECT))
            && ! $user->checkPermissionTo(self::permission('view', self::SUBJECT))
        ) {
            return false;
        }

        return MediaScope::actorCanUseMedia($user, $media);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', self::SUBJECT));
    }

    public function update(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('update', self::SUBJECT))
            && MediaScope::actorCanUseMedia($user, $media);
    }

    public function delete(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('delete', self::SUBJECT))
            && MediaScope::actorCanUseMedia($user, $media);
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', self::SUBJECT));
    }

    public function restore(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('restore', self::SUBJECT))
            && MediaScope::actorCanUseMedia($user, $media);
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', self::SUBJECT));
    }

    public function forceDelete(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', self::SUBJECT))
            && MediaScope::actorCanUseMedia($user, $media);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', self::SUBJECT));
    }
}
