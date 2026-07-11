<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class AbstractPackageSettingsPageTestSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            TextInput::make('headline'),
        ];
    }
}
