<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Data\ApplicationNavigationItemData;
use Illuminate\Http\Request;

/**
 * Supplies the hydrated navigation shown around an application area's pages.
 *
 * @experimental
 */
interface ApplicationNavigationResolver
{
    /**
     * @return list<ApplicationNavigationItemData>
     */
    public function resolve(ApplicationAreaData $area, Site $site, Language $language, Request $request): array;
}
