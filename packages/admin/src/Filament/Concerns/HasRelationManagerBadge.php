<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin  RelationManager
 */
trait HasRelationManagerBadge
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $relationship = static::$relationship;

        if (! $ownerRecord->isRelation($relationship)) {
            return null;
        }

        $query = $ownerRecord->getRelationValue($relationship);

        $count = $query->count();

        if ($count === 0) {
            return null;
        }

        return (string) $count;
    }
}
