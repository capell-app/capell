<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RedirectToInstallerWhenCapellIsNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null || ! Route::has('capell-installer.show') || $this->capellIsInstalled()) {
            return $next($request);
        }

        return new RedirectResponse(route('capell-installer.show'));
    }

    private function capellIsInstalled(): bool
    {
        try {
            return CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled()
                && Schema::hasTable((new Site)->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
