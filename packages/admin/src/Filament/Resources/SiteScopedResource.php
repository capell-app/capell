<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources;

use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Override;

/** Site-owned resources inherit access constraints for listing and record resolution. */
abstract class SiteScopedResource extends Resource
{
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return SiteAccess::current()->scope(parent::getEloquentQuery());
    }

    #[Override]
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return SiteAccess::current()->scope(parent::getGlobalSearchEloquentQuery());
    }
}
