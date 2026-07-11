<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class MyWorkQueueData extends Data
{
    /**
     * @param  DataCollection<int, MyWorkItemData>  $items
     */
    public function __construct(
        public readonly DataCollection $items,
    ) {}
}
