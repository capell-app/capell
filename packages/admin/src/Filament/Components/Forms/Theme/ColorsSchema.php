<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

class ColorsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            Grid::make(['@sm' => 3])
                ->gridContainer()
                ->columnSpanFull()
                ->schema([
                    ColorPicker::make('link_color')
                        ->label(__('capell-admin::form.link_color'))
                        ->autoFormat(),
                    ColorPicker::make('link_color_active')
                        ->label(__('capell-admin::form.link_color_active'))
                        ->autoFormat(),
                    ColorPicker::make('divider_color')
                        ->label(__('capell-admin::form.divider_color'))
                        ->autoFormat(),
                ]),
            Section::make(__('capell-admin::form.colors'))
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    ColorsRepeater::make(),
                ]),
        ];
    }
}
