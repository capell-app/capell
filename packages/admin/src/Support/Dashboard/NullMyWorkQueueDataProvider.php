<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\MyWorkQueueDataProvider;
use Capell\Admin\Data\Dashboard\MyWorkItemData;
use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\DataCollection;

final class NullMyWorkQueueDataProvider implements MyWorkQueueDataProvider
{
    public function build(Authenticatable $user, int $limit): MyWorkQueueData
    {
        return new MyWorkQueueData(
            items: MyWorkItemData::collect([], DataCollection::class),
        );
    }
}
