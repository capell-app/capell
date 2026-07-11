<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Filament\Tables\Table;

trait HasConfiguredRelationManagerTable
{
    use InteractsWithConfiguredTable;

    public function table(Table $table): Table
    {
        return static::configuredTable($table);
    }
}
