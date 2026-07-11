<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

use Filament\Tables\Table;

interface TableConfigurator
{
    public static function configure(Table $table): Table;
}
