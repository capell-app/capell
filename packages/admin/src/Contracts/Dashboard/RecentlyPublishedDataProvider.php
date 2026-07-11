<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\RecentlyPublishedData;

interface RecentlyPublishedDataProvider
{
    public function build(int $limit): RecentlyPublishedData;
}
