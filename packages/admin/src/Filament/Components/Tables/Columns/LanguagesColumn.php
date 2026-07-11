<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

class LanguagesColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();
        /** @var view-string $view */
        $view = 'capell-admin::components.tables.columns.language-flags';

        $this->label(__('capell-admin::table.languages'))
            ->view($view)
            ->toggleable(isToggledHiddenByDefault: true);
    }
}
