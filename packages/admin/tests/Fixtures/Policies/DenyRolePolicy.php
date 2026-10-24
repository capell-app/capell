<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Policies;

final class DenyRolePolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}
