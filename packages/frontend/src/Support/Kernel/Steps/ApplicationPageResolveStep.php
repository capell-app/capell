<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolveSystemPageAction;
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Data\FrontendWork;
use Closure;

/**
 * Resolves an application area's shell page by its system page type, in place of
 * resolving a CMS page from the URL.
 */
final class ApplicationPageResolveStep
{
    public const string AREA_ATTRIBUTE = 'capell.frontend.application_area';

    public const int MISSING_PAGE_STATUS = 503;

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $site = $work->state->site();
        $language = $work->state->language();
        $area = $work->request->attributes->get(self::AREA_ATTRIBUTE);

        if (! $site instanceof Site || ! $language instanceof Language || ! $area instanceof ApplicationAreaData) {
            return $next($work);
        }

        $page = ResolveSystemPageAction::run($area->pageType, $site, $language);

        if (! $page instanceof Pageable) {
            $work->setError(['status' => self::MISSING_PAGE_STATUS, 'message' => $area->pageType]);

            return $work;
        }

        $work->state->withPage($page);
        $work->state->markAsError(false);

        return $next($work);
    }
}
