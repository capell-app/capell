<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

final class ScopedAdminUser
{
    /** @param Collection<int, int> $assignedSiteIds */
    public static function make(Collection $assignedSiteIds): Authenticatable
    {
        $user = new class extends Authenticatable implements FilamentUser
        {
            /** @use HasFactory<Factory<static>> */
            use HasFactory;

            /** @var Collection<int, int> */
            public Collection $assignedSiteIds;

            protected $table = 'users';

            public function canAccessPanel(Panel $panel): bool
            {
                return true;
            }

            /** @return Collection<int, int> */
            public function getAssignedSiteIds(): Collection
            {
                return $this->assignedSiteIds;
            }

            public function isGlobalAdmin(): bool
            {
                return false;
            }

            public function hasRole(string $role): bool
            {
                return false;
            }

            public function checkPermissionTo(mixed $permission, ?string $guardName = null): bool
            {
                return true;
            }
        };

        $user->forceFill([
            'name' => 'Scoped Admin User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ]);
        $user->assignedSiteIds = $assignedSiteIds;

        return $user;
    }
}
