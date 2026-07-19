<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Cache;

use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Core\Models\Site;
use RuntimeException;

final class UnavailableStaticSiteGenerationDispatcher implements StaticSiteGenerationDispatcher
{
    public function dispatch(Site $site): void
    {
        throw new RuntimeException('Static site generation requires an installed cache extension.');
    }
}
