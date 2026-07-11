<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Widgets;

use Filament\Forms\Components\Builder\Block;

interface FilamentWidget
{
    public static function getWidgetName(): string;

    public static function make(): Block;
}
