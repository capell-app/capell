<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Admin\Tests\Fixtures\Policies\DenyRolePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Spatie\Permission\Models\Role;

#[UsePolicy(DenyRolePolicy::class)]
class AttributedRole extends Role {}
