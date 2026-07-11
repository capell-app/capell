<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;

class HeroWidget implements FilamentWidget
{
    public static function getWidgetName(): string
    {
        return 'hero';
    }

    public static function make(): Block
    {
        return Block::make('hero')
            ->label('Hero')
            ->schema([
                TextInput::make('heading'),
            ]);
    }
}
