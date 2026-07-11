<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\State\FrontendState;
use Closure;

final class CommitContextStep
{
    public function __construct(private readonly FrontendState $state) {}

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $context = $work->context();
        if ($context instanceof FrontendContext) {
            if ($context->site instanceof Site) {
                $this->state->withSite($context->site);
            }

            if ($context->language instanceof Language) {
                $this->state->withLanguage($context->language);
            }

            if ($context->page instanceof Pageable) {
                $this->state->withPage($context->page);
            }

            if ($context->layout instanceof Layout) {
                $this->state->withLayout($context->layout);
            }

            if ($context->theme instanceof Theme) {
                $this->state->withTheme($context->theme);
            }

            $this->state->markAsError($context->isError());
            $this->state->withParams($context->params);
            $this->state->withSlug($context->slug);
        }

        return $next($work);
    }
}
