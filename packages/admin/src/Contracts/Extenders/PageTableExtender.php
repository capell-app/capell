<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface PageTableExtender
{
    public const string TAG = 'capell-admin:page-table-extender';

    /** @return array<int, Column> */
    public function getColumns(): array;

    /** @return array<int, BulkAction> */
    public function getBulkActions(): array;

    /** @return array<int, BaseFilter> */
    public function getFilters(): array;

    /**
     * Applied to page queries and to sub-selects (parent picker, page select
     * form components) where global scopes must be removed.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function modifyQuery(Builder $query): Builder;
}
