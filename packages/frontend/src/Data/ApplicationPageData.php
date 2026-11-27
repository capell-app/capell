<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;

/**
 * Hydrated render data for a host route rendered inside the theme layout. The
 * `capell::application` view reads only this; it never queries.
 *
 * @experimental
 */
final readonly class ApplicationPageData
{
    public const string ATTRIBUTE = 'capell.frontend.application_page';

    public const string DEFAULT_ROBOTS = 'noindex,nofollow';

    /**
     * @param  list<ApplicationNavigationItemData>  $navigation
     */
    public function __construct(
        public ApplicationAreaData $area,
        public Pageable $page,
        public Site $site,
        public Language $language,
        public ?Layout $layout,
        public ?Theme $theme,
        public string $layoutFile,
        public array $navigation = [],
        public string $robots = self::DEFAULT_ROBOTS,
    ) {}
}
