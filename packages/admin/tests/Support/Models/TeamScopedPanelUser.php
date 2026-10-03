<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support\Models;

use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Tests\Fixtures\Models\User;
use Filament\Panel;
use Override;

final class TeamScopedPanelUser extends User
{
    use HasSitePermissions;

    protected $table = 'users';

    public function guardName(): string
    {
        return 'web';
    }

    #[Override]
    public function getMorphClass(): string
    {
        return User::class;
    }

    #[Override]
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
