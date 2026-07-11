<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use Capell\Frontend\Contracts\AdminAccessCheckerInterface;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Throwable;

class FilamentAdminAccessChecker implements AdminAccessCheckerInterface
{
    public function isAdmin(AuthenticatableContract $user): bool
    {
        if (! method_exists($user, 'canAccessPanel')) {
            return false;
        }

        if (! app()->bound('filament')) {
            return false;
        }

        if (! class_exists(Panel::class)) {
            return false;
        }

        try {
            $panel = Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            return false;
        }

        if (! $panel instanceof Panel) {
            return false;
        }

        return $user->canAccessPanel($panel);
    }
}
