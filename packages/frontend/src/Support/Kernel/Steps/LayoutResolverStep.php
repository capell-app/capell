<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Exceptions\LayoutNotFoundException;
use Capell\Core\Models\Layout;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Data\FrontendWork;
use Closure;

final class LayoutResolverStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $page = $work->state->page();
        $site = $work->state->site();

        $layout = $page?->layout ?? $site?->layout ?? null;
        $layoutCameFromRelation = $layout instanceof Layout;

        if (! $layout instanceof Layout) {
            $defaultKey = config('capell-frontend.default_layout', 'default');
            if ($defaultKey !== '') {
                $layout = Layout::query()->where('key', $defaultKey)->first();
            }
        }

        if ($layoutCameFromRelation && $layout instanceof Layout) {
            resolve(RenderedModelTracker::class)->track($layout);
        }

        throw_unless($layout instanceof Layout, LayoutNotFoundException::class, 'No theme could be resolved for the frontend request.');

        $work->state->withLayout($layout);

        return $next($work);
    }
}
