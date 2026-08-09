<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageAvailabilityData;
use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageAvailabilityStateAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TModel of Model
     *
     * @param  Model&Pageable<TModel>  $page
     */
    public function handle(Model&Pageable $page): PageAvailabilityData
    {
        return PageAvailabilityData::fromPage($page);
    }
}
