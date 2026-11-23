<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

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

        return SiteAccess::forActor($user)->canUseMedia($media);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', self::SUBJECT));
    }

    public function update(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('update', self::SUBJECT))
            && SiteAccess::forActor($user)->canUseMedia($media)
            && $this->owningPageAllows($user, $media);
    }

    public function delete(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('delete', self::SUBJECT))
            && SiteAccess::forActor($user)->canUseMedia($media)
            && $this->owningPageAllows($user, $media);
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', self::SUBJECT));
    }

    public function restore(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('restore', self::SUBJECT))
            && SiteAccess::forActor($user)->canUseMedia($media)
            && $this->owningPageAllows($user, $media);
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', self::SUBJECT));
    }

    public function forceDelete(User $user, Media $media): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', self::SUBJECT))
            && SiteAccess::forActor($user)->canUseMedia($media)
            && $this->owningPageAllows($user, $media);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', self::SUBJECT));
    }

    /**
     * Media attached to a page, or to one of its translations, changes what that
     * page shows, so changing or removing it needs the page policy's update
     * decision as well, which carries the page-type role restrictions. Media on
     * anything else is governed by the media permissions and site access alone.
     */
    private function owningPageAllows(User $user, Media $media): bool
    {
        // A trashed page still carries its page-type restrictions.
        $media->loadMissing(['model' => fn (MorphTo $owner): MorphTo => $owner->withTrashed()]);
        $owner = $media->model;

        if ($owner instanceof Translation) {
            $owner->loadMissing(['translatable' => fn (MorphTo $page): MorphTo => $page->withTrashed()]);
            $owner = $owner->translatable;
        }

        if (! $owner instanceof Page) {
            return true;
        }

        return Gate::forUser($user)->allows('update', $owner);
    }
}
