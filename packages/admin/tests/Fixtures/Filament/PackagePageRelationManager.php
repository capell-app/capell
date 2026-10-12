<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament;

use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

class PackagePageRelationManager extends UrlsRelationManager
{
    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('View:PackagePageRelations') === true;
    }

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Package page relations';
    }
}
