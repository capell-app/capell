<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Layout\Tab;

use Capell\Admin\Filament\Components\Forms\Layout\DetailsSchema;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SettingsTab
{
    public static function make(Schema $schema): Tab
    {
        return Tab::make(__('capell-admin::tab.settings'))
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->columns()
            ->schema(DetailsSchema::make($schema));
    }
}
