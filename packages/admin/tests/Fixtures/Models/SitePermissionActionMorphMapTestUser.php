<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Tests\Fixtures\Models\User;

final class SitePermissionActionMorphMapTestUser extends User
{
    use HasSitePermissions;

    protected $table = 'users';

    protected string $guard_name = 'web';
}
