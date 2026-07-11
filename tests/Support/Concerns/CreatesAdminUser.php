<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Concerns;

use Capell\Tests\AbstractTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;

/**
 * @property-read Application $app
 *
 * @mixin AbstractTestCase
 */
trait CreatesAdminUser
{
    /**
     * {@inheritdoc}
     */
    public function actingAs(UserContract $user, $guard = null): static
    {
        return parent::actingAs($user, $guard ?? 'web');
    }

    public function actingAsRole(string $role, array $attributes = []): static
    {
        return $this->actingAs($this->createUserWithRole($role, $attributes));
    }

    public function actingAsUser(array $attributes = []): static
    {
        return $this->actingAs($this->createUser($attributes));
    }

    public function actingAsAdmin(array $attributes = []): static
    {
        return $this->actingAsRole('super_admin', $attributes);
    }

    public function createUser(array $attributes = []): User
    {
        return User::factory()->createOne($attributes);
    }

    public function createUserWithRole(
        array|string|int|Role|Collection $roles,
        array $attributes = [],
    ): User {
        return $this->createUser($attributes)->assignRole($roles);
    }

    public function createUserWithPermission(
        string|int|array|Permission|Collection $permissions,
        array $attributes = [],
    ): User {
        return $this->createUser($attributes)->givePermissionTo($permissions);
    }

    public function authenticatedUser(): User
    {
        $user = auth()->user();

        throw_unless($user instanceof User, RuntimeException::class, 'Expected an authenticated test user.');

        return $user;
    }
}
