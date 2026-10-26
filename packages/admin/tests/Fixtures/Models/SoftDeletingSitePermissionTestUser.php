<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;

final class SoftDeletingSitePermissionTestUser extends User
{
    use HasSitePermissions;
    use SoftDeletes;

    protected $table = 'users';

    protected string $guard_name = 'web';

    #[Override]
    public function getMorphClass(): string
    {
        return User::class;
    }
}
