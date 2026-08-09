<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Pages;

use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface PageTableStatusResolver
{
    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function modifyQuery(Builder $query): Builder;

    /**
     * @template TModel of Model
     *
     * @param  Model&Pageable<TModel>&Publishable  $page
     */
    public function resolve(Model&Pageable&Publishable $page): PageTableStatusData;
}
