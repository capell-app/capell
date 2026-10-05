<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstalledPanelAvailable
{
    public function __construct(private readonly InstalledRuntimeLifecycle $lifecycle, private readonly InstalledPanelRuntime $panels) {}

    public function handle(Request $request, Closure $next, string $panel): Response
    {
        $this->assertAvailable($panel);
        $response = $next($request);
        $this->assertAvailable($panel);

        return $response;
    }

    private function assertAvailable(string $panel): void
    {
        abort_if($this->lifecycle->isUnavailable() || $this->panels->isUnavailable($panel), 503, __('capell::runtime-refresh.application_unavailable'));
    }
}
