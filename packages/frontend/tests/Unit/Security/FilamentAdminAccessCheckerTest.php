<?php

declare(strict_types=1);

use Capell\Frontend\Support\Security\FilamentAdminAccessChecker;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;

it('rejects users that do not expose Filament panel access checks', function (): void {
    expect(resolve(FilamentAdminAccessChecker::class)->isAdmin(new User))->toBeFalse();
});

it('rejects panel-aware users when Filament is unavailable from the container', function (): void {
    $user = new class extends User
    {
        use HasFactory;

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    };

    app()->forgetInstance('filament');
    app()->offsetUnset('filament');

    expect(resolve(FilamentAdminAccessChecker::class)->isAdmin($user))->toBeFalse();
});
