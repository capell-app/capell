<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\RelationManagers;

use BackedEnum;
use Capell\Admin\Filament\RelationManagers\AbstractPagesRelationManager;
use Capell\Core\Models\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property Page $ownerRecord
 */
class ChildrenRelationManager extends AbstractPagesRelationManager
{
    protected static string|BackedEnum|null $icon = Heroicon::OutlinedUserGroup;

    protected static string $relationship = 'children';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('capell-admin::tab.children');
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('capell-admin::generic.page_children');
    }

    protected function getDescription(Table $table): ?string
    {
        return __('capell-admin::generic.page_children_description');
    }

    #[Override]
    protected function getEmptyStateHeading(): string
    {
        return __('capell-admin::generic.no_child_pages');
    }

    #[Override]
    protected function getEmptyStateDescription(): string
    {
        return __('capell-admin::generic.no_child_pages_description');
    }
}
