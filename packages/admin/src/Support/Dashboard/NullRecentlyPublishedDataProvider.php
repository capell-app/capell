<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\RecentlyPublishedDataProvider;
use Capell\Admin\Data\Dashboard\RecentlyPublishedData;
use Capell\Admin\Data\Dashboard\RecentlyPublishedItemData;
use Spatie\LaravelData\DataCollection;

final class NullRecentlyPublishedDataProvider implements RecentlyPublishedDataProvider
{
    public function build(int $limit): RecentlyPublishedData
    {
        return new RecentlyPublishedData(
            items: RecentlyPublishedItemData::collect([], DataCollection::class),
        );
    }
}
