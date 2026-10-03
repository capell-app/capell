<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Filament\Resources\Pages\RelationManagers\ChildrenRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\SiblingsRelationManager;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait HasDefaultRelationManagers
{
    /**
     * @param  Page  $record
     * @return array<int, class-string>
     */
    public static function relationManagers(Model $record): array
    {
        if ($record->getAttributeValue('children_count') === null) {
            $record->loadCount(['children' => fn (Builder $query): Builder => SiteAccess::current()->scope($query)]);
        }

        if ($record->getAttributeValue('siblings_count') === null) {
            $record->loadCount(['siblings' => fn (Builder $query): Builder => SiteAccess::current()->scope($query)]);
        }

        return [
            UrlsRelationManager::class,
            ...($record->children_count > 0 ? [ChildrenRelationManager::class] : []),
            ...($record->siblings_count > 0 ? [SiblingsRelationManager::class] : []),
        ];
    }
}
