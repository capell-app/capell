<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Core\Support\CapellCoreHelper;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin RelationManager
 */
trait HideEmptyRelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! parent::canViewForRecord($ownerRecord, $pageClass)) {
            return false;
        }

        return CapellCoreHelper::relationExists($ownerRecord, static::$relationship);
    }
}
