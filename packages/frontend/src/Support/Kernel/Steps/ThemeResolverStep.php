<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Exceptions\ThemeNotFoundException;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Data\FrontendWork;
use Closure;

final class ThemeResolverStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $page = $work->state->page();
        $site = $work->state->site();

        $theme = $page?->layout?->theme ?? $site?->theme ?? null;
        $themeCameFromRelation = $theme instanceof Theme;

        if (! $theme instanceof Theme) {
            $defaultKey = config('capell-frontend.foundation_theme', 'default');
            if ($defaultKey !== '') {
                $theme = Theme::query()->where('key', $defaultKey)->first();
            }
        }

        if ($themeCameFromRelation && $theme instanceof Theme) {
            resolve(RenderedModelTracker::class)->track($theme);
        }

        if (! $theme instanceof Theme) {
            throw ThemeNotFoundException::unresolvedForRequest();
        }

        $work->state->withTheme($theme);

        return $next($work);
    }
}
