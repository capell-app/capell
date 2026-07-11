<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\RelationManagers;

use Capell\Admin\Filament\RelationManagers\AbstractPagesRelationManager;
use Capell\Core\Models\Page;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property Page $ownerRecord
 */
class SiblingsRelationManager extends AbstractPagesRelationManager
{
    protected static string $relationship = 'siblings';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.siblings');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('capell-admin::generic.page_siblings');
    }

    protected function getDescription(Table $table): ?string
    {
        return __('capell-admin::generic.page_siblings_description');
    }

    #[Override]
    protected function getEmptyStateHeading(): string
    {
        return __('capell-admin::generic.no_sibling_pages');
    }

    #[Override]
    protected function getEmptyStateDescription(): string
    {
        return __('capell-admin::generic.no_sibling_pages_description');
    }

    #[Override]
    protected function modifyQuery(Builder $query): Builder
    {
        $query = parent::modifyQuery($query);

        $query->where('id', '!=', $this->ownerRecord->id);

        return $query;
    }
}
