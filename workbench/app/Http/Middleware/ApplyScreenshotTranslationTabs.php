<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Capell\Admin\Enums\TranslationTabsOrientation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screenshot workbench only. The runner cannot change configuration between
 * captures, so a fixture route stores the wanted translation tab orientation
 * in the session and every later request, Livewire updates included, applies
 * it. Unknown values are ignored.
 */
final class ApplyScreenshotTranslationTabs
{
    public const string SESSION_KEY = 'screenshot.translation_tabs_orientation';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $orientation = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (is_string($orientation) && TranslationTabsOrientation::tryFrom($orientation) instanceof TranslationTabsOrientation) {
            config()->set('capell-admin.translation_tabs_orientation', $orientation);
        }

        return $next($request);
    }
}
