<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Policies\Fixtures;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;

class TestPermissionResolver
{
    use ResolvesShieldPermission {
        permission as public;
    }
}
