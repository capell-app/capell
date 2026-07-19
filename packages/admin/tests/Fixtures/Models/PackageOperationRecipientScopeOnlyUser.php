<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

final class PackageOperationRecipientScopeOnlyUser extends Authenticatable
{
    use HasFactory;
    use HasRoles;

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
        return $query;
    }
}
