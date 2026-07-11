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
        $user = Filament::auth()->user();

        if (! $this->lockdownStore->canAccessAdmin($user)) {
            throw new HttpException(
                Response::HTTP_LOCKED,
                __('capell-admin::message.lockdown_admin_locked'),
            );
        }

        return $next($request);
    }
}
