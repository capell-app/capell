<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Filament\Forms\Components\Builder\Block;

final class StateIntegrityFilamentWidget implements FilamentWidget
{
    public static function getWidgetName(): string
    {
        return 'capell-app.state-integrity';
    }

    public static function make(): Block
    {
        return Block::make(self::getWidgetName())->schema([]);
    }
}
