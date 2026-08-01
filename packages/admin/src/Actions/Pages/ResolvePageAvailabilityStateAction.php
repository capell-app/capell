<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageAvailabilityData;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageAvailabilityStateAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): PageAvailabilityData
    {
        return PageAvailabilityData::fromPage($page);
    }
}
