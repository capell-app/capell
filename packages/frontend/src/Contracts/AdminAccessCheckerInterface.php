<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;

interface AdminAccessCheckerInterface
{
    public function isAdmin(AuthenticatableContract $user): bool;
}
