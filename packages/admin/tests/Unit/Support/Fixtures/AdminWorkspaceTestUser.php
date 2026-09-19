<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;

final class AdminWorkspaceTestUser extends AuthenticatableUser
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(private array $roles = [], private array $permissions = [], private bool $global = false)
    {
        parent::__construct();
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function checkPermissionTo(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isGlobalAdmin(): bool
    {
        return $this->global;
    }
}
