<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\Install\AdminInstallState;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class RedirectToInstallerWhenCapellIsNotInstalled
{
    public function __construct(private readonly AdminInstallState $installState) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! Route::has('capell-installer.show') || $this->installState->installed()) {
            return $next($request);
        }

        return new RedirectResponse(route('capell-installer.show'));
    }
}
