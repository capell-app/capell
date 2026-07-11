<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Filament\Tables;

class ImageColumn extends Tables\Columns\ImageColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->alignCenter()
            ->label(__('capell-admin::table.image'))
            ->imageSize(100)
            ->width(0);
    }
}
