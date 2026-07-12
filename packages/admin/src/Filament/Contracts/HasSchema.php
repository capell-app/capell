<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

use Capell\Core\Contracts\SettingsSchema;
use Filament\Schemas\Schema;

interface HasSchema extends SettingsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(Schema $schema): array;
}
