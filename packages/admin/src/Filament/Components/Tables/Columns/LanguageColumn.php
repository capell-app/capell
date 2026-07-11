<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

class LanguageColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();
        /** @var view-string $view */
        $view = 'capell-admin::components.tables.columns.flag';

        $this->label(__('capell-admin::table.language'))
            ->alignCenter()
            ->view($view);
    }
}
