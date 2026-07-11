<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Configurators;

use Filament\Tables\Table;

interface ConfiguresTable
{
    public static function configureTable(Table $table): Table;
}
