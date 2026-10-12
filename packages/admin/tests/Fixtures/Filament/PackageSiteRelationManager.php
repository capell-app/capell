<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament;

use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Illuminate\Database\Eloquent\Model;
use Override;

class PackageSiteRelationManager extends SiteDomainsRelationManager
{
    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('View:PackageNavigation') === true;
    }

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Package navigations';
    }
}
