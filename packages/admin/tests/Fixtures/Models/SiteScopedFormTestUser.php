<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Capell\Tests\Fixtures\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Support\Collection;
use Override;

final class SiteScopedFormTestUser extends User implements FilamentUser
{
    protected $table = 'users';

    /** @var array<int, Collection<int, int>> */
    private static array $assignedSiteIdsByUserKey = [];

    /** @param Collection<int, int> $assignedSiteIds */
    public static function rememberAssignedSiteIds(int $userKey, Collection $assignedSiteIds): void
    {
        self::$assignedSiteIdsByUserKey[$userKey] = $assignedSiteIds;
    }

    #[Override]
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    #[Override]
    public function isGlobalAdmin(): bool
    {
        return false;
    }

    #[Override]
    public function getMorphClass(): string
    {
        return User::class;
    }

    /** @return Collection<int, int> */
    #[Override]
    public function getAssignedSiteIds(): Collection
    {
        $key = $this->getKey();

        if (is_int($key) && isset(self::$assignedSiteIdsByUserKey[$key])) {
            return self::$assignedSiteIdsByUserKey[$key];
        }

        return collect();
    }
}
