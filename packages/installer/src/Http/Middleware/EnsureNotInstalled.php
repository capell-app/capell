<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Middleware;

use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerSessionRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EnsureNotInstalled
{
    public function __construct(
        private readonly ?InstallerSessionRepository $sessions = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isInstalled()) {
            return $next($request);
        }

        $request->attributes->set('capellAlreadyInstalled', true);

        if (config('capell-installer.allow_reinstall', false)) {
            $request->attributes->set('capellCanReinstall', true);

            return $next($request);
        }

        if ($request->routeIs('capell-installer.show')) {
            return $next($request);
        }

        throw new NotFoundHttpException;
    }

    private function isInstalled(): bool
    {
        // If an install is currently in flight, allow access to install routes
        // so the step loop can finish even after `mark-core-installed` runs.
        if ($this->sessions()->hasActiveInstallLock()) {
            return false;
        }

        return InstallerInstallationState::capellIsInstalled();
    }

    private function sessions(): InstallerSessionRepository
    {
        return $this->sessions ?? resolve(InstallerSessionRepository::class);
    }
}
