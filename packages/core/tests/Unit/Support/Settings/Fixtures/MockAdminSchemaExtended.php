<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Settings\Fixtures;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Schemas\Schema;

class MockAdminSchemaExtended implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [];
    }
}
