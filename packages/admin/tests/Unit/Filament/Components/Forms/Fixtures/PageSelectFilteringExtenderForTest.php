<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Components\Forms\Fixtures;

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;

final class PageSelectFilteringExtenderForTest implements PageTableExtender
{
    /** @return array<int, Column> */
    public function getColumns(): array
    {
        return [];
    }

    /** @return array<int, BulkAction> */
    public function getBulkActions(): array
    {
        return [];
    }

    /** @return array<int, BaseFilter> */
    public function getFilters(): array
    {
        return [];
    }

    public function modifyQuery(Builder $query): Builder
    {
        return $query->where('pages.name', 'not like', 'Hidden%');
    }
}
