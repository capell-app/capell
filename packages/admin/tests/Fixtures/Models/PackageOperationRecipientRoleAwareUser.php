<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class PackageOperationRecipientRoleAwareUser extends User
{
    protected $table = 'users';

    public function guardName(): string
    {
        return 'web';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeGlobalAdmins(Builder $query): Builder
    {
        $roleName = (string) config('filament-shield.super_admin.name', 'super_admin');

        return $query->whereHas('roles', static fn (Builder $roleQuery): Builder => $roleQuery
            ->where('roles.name', $roleName)
            ->where('roles.guard_name', 'web')
            ->whereNull('model_has_roles.team_id'));
    }
}
