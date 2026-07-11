<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;

final class FrontendRuleEngineUser extends User
{
    use HasFactory;

    public function hasRole(string $role): bool
    {
        return $role === 'admin';
    }
}
