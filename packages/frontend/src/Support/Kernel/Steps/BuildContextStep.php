<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendWork;
use Closure;

final class BuildContextStep
{
    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $state = $work->state;
        $context = new FrontendContext(
            site: $state->site(),
            language: $state->language(),
            page: $state->page(),
            layout: $state->layout(),
            theme: $state->theme(),
            params: $state->params(),
            slug: $state->slug(),
            isError: $state->isError(),
        );

        $work->setContext($context);

        return $next($work);
    }
}
