<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Application;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\ApplicationNavigationResolver;
use Capell\Frontend\Data\ApplicationAreaData;
use Illuminate\Http\Request;
use Override;

final class NullApplicationNavigationResolver implements ApplicationNavigationResolver
{
    #[Override]
    public function resolve(ApplicationAreaData $area, Site $site, Language $language, Request $request): array
    {
        return [];
    }
}
