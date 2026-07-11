<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Pages;

use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;

interface PageTableStatusResolver
{
    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function modifyQuery(Builder $query): Builder;

    public function resolve(Page $page): PageTableStatusData;
}
