<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireScreenshotAdmin
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        abort_unless(
            $actor instanceof Authenticatable
            && $actor instanceof FilamentUser
            && $actor->canAccessPanel(Filament::getPanel('admin')),
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
