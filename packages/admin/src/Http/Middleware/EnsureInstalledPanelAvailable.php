<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstalledPanelAvailable
{
    public function __construct(private readonly InstalledRuntimeLifecycle $lifecycle, private readonly InstalledPanelRuntime $panels) {}

    public function handle(Request $request, Closure $next, string $panel): Response
    {
        $this->assertAvailable($panel, $request);
        $response = $next($request);
        $this->assertAvailable($panel, $request);

        return $response;
    }

    private function assertAvailable(string $panel, Request $request): void
    {
        // A Livewire update replays the original route's middleware without matching that route.
        $route = $request->route();
        abort_if($this->lifecycle->isUnavailable() || $this->panels->isUnavailable($panel) || $this->panels->isRouteRefused($route instanceof Route ? $route : null), 503, __('capell-core::runtime-refresh.application_unavailable'));
    }
}
