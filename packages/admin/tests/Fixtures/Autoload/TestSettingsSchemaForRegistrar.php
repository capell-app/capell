<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Schemas\Schema;

final class TestSettingsSchemaForRegistrar implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [];
    }
}
