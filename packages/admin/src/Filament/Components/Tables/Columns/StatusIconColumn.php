<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Filament\Tables\Columns\IconColumn;

class StatusIconColumn extends IconColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.status'))
            ->toggleable()
            ->sortable()
            ->icons([
                'heroicon-m-check-circle' => true,
                'heroicon-m-exclamation-circle' => false,
            ])
            ->colors([
                'danger' => false,
                'success' => true,
            ])
            ->alignCenter()
            ->width(0)
            ->extraAttributes(['class' => 'table-cell-action-icon']);
    }
}
