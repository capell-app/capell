<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Admin\Support\Loader\SiteLoader;
use Filament\Tables\Columns\TextColumn;

class SiteColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.site'))
            ->sortable()
            ->limit(30)
            ->size('sm')
            ->toggleable(
                isToggledHiddenByDefault: fn (): bool => SiteLoader::total() < 2,
            );
    }
}
