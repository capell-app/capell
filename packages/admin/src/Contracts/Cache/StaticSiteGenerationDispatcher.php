<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Cache;

use Capell\Core\Models\Site;

interface StaticSiteGenerationDispatcher
{
    public function dispatch(Site $site): void;
}
