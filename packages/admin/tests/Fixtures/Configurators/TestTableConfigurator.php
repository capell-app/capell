<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Configurators;

use Capell\Admin\Filament\Contracts\TableConfigurator;
use Filament\Tables\Table;

class TestTableConfigurator implements TableConfigurator
{
    public static int $configurationCount = 0;

    public static function configure(Table $table): Table
    {
        self::$configurationCount++;

        return $table;
    }
}
