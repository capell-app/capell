<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Stubs;

use Capell\Admin\Filament\Contracts\TableConfigurator;
use Filament\Tables\Table;

class ExampleTable implements TableConfigurator
{
    public static function configure(Table $table): Table
    {
        return $table;
    }
}
