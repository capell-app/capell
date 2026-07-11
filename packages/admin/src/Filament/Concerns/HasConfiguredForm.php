<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Filament\Schemas\Schema;

trait HasConfiguredForm
{
    use InteractsWithConfiguredForm;

    public static function form(Schema $schema): Schema
    {
        return static::configuredForm($schema);
    }
}
