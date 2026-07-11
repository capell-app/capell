<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Models;

use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Tests\Fixtures\Models\User;
use Override;

final class HasSitePermissionsTestUser extends User
{
    use HasSitePermissions;

    protected $table = 'users';

    private string $guard_name = 'web';

    #[Override]
    public function getMorphClass(): string
    {
        return User::class;
    }
}
