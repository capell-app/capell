<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Layouts\RelationManagers;

use Capell\Admin\Filament\RelationManagers\AbstractPagesRelationManager;
use Filament\Tables\Table;
use Override;

class PagesRelationManager extends AbstractPagesRelationManager
{
    #[Override]
    protected function getDescription(Table $table): ?string
    {
        $query = $table->getQuery();
        $total = $query?->count() ?? 0;

        return trans_choice('capell-admin::generic.page_layout_info', $total, ['total' => $total]);
    }

    #[Override]
    protected function getEmptyStateHeading(): string
    {
        return __('capell-admin::generic.no_layout_pages');
    }

    #[Override]
    protected function getEmptyStateDescription(): string
    {
        return __('capell-admin::generic.no_layout_pages_description');
    }
}
