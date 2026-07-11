<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Filament\Tables\Table;

trait HasConfiguredTable
{
    use InteractsWithConfiguredTable;

    public static function table(Table $table): Table
    {
        return static::configuredTable($table);
    }
}
