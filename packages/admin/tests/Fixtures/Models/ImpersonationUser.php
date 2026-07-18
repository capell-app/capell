<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Tests\Fixtures\Models\User;

final class ImpersonationUser extends User
{
    use HasImpersonation;
}
