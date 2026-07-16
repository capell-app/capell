<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Extends page table presentation and reusable page-selection queries.
 *
 * Bind implementations in the service container and tag them with TAG.
 * Returned Filament components are appended in registration order. Query
 * mutation must remain composable because it is reused outside the main table.
 */
interface PageTableExtender
{
    public const string TAG = 'capell-admin:page-table-extender';

    /** @return list<Column> */
    public function getColumns(): array;

    /** @return list<BulkAction> */
    public function getBulkActions(): array;

    /** @return list<BaseFilter> */
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
