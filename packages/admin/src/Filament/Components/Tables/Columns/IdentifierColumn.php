<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IdentifierColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.id'))
            ->grow(false)
            ->sortable()
            ->searchable(query: self::applyIdentifierSearch(...))
            ->toggleable(isToggledHiddenByDefault: true);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function applyIdentifierSearch(Builder $query, string $search): Builder
    {
        return $query->whereKey($search);
    }
}
