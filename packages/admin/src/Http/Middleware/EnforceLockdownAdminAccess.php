<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Core\Support\Security\LockdownStore;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EnforceLockdownAdminAccess
{
    public function __construct(private readonly LockdownStore $lockdownStore) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // Standalone admin routes authenticate with Laravel's guard, the panel with Filament's; honour whichever resolved the user.
        $user = $request->user() ?? Filament::auth()->user();

        if ($user !== null && ! $this->lockdownStore->canAccessAdmin($user)) {
            throw new HttpException(
                Response::HTTP_LOCKED,
                __('capell-admin::message.lockdown_admin_locked'),
            );
        }

        return $next($request);
    }
}
