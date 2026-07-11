<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Illuminate\Foundation\Auth\User;

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
        return $user->checkPermissionTo(self::permission('update', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function delete(User $user, PageUrl $record): bool
    {
        return $user->checkPermissionTo(self::permission('delete', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', $this->subject()));
    }

    public function restore(User $user, PageUrl $record): bool
    {
        return $user->checkPermissionTo(self::permission('restore', $this->subject()))
            && $this->canAccessRecord($user, $record);
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', $this->subject()));
    }

    public function forceDelete(User $user, PageUrl $record): bool
    {
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

    private function canAccessRecord(User $user, PageUrl $record): bool
    {
        $record->loadMissing('site');

        return SiteScope::actorCanUseSite($user, $record->site);
    }
}
