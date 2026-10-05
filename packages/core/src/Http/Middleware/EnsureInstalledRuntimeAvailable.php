<?php

declare(strict_types=1);

namespace Capell\Core\Http\Middleware;

use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureInstalledRuntimeAvailable
{
    public function __construct(private readonly InstalledRuntimeLifecycle $lifecycle) {}

    public function handle(Request $request, Closure $next, ?string $package = null): Response
    {
        abort_if(($package === null ? $this->lifecycle->isUnavailable() : $this->lifecycle->packageFailed($package)), 503, __('capell::runtime-refresh.application_unavailable'));

        $response = $next($request);
        abort_if(($package === null ? $this->lifecycle->isUnavailable() : $this->lifecycle->packageFailed($package)), 503, __('capell::runtime-refresh.application_unavailable'));

        return $response;
    }
}
