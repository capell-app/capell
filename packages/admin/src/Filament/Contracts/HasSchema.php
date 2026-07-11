<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

use Filament\Schemas\Schema;

interface HasSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(Schema $schema): array;
}
