<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PublishSchema
{
    public static function make(Schema $schema): Component
    {
        // Edit surfaces now render the standalone PublishStatusPanel Livewire
        // component (wired in the configurators), so the inline schema only ever
        // needs the slim create-style publish-date field.
        return self::getCreateSchema();
    }

    private static function getCreateSchema(): Grid
    {
        return Grid::make()
            ->schema([
                PublishDatesGrid::getVisibleFromField(),
            ]);
    }
}
