<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Core\Support\Security\LockdownStore;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EnforceLockdownAdminAccess
{
    public function __construct(private readonly LockdownStore $lockdownStore) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Standalone admin routes authenticate with Laravel's guard, the panel with Filament's; honour whichever resolved the user.
        $adminGuard = Filament::getCurrentOrDefaultPanel()?->getAuthGuard();
        $user = $request->user();
        $guard = Auth::getDefaultDriver();
        if ($user === null) {
            $user = Filament::auth()->user();
            $guard = $adminGuard;
        }

        if ($user !== null && ! $this->lockdownStore->canAccessAdmin($user, $guard, $adminGuard)) {
            throw new HttpException(
                Response::HTTP_LOCKED,
                __('capell-admin::message.lockdown_admin_locked'),
            );
        }

        return $next($request);
    }
}
