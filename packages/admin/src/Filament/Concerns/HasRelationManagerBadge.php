<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

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

        $relation = $ownerRecord->{$relationship}();

        if (! $relation instanceof Relation) {
            return null;
        }

        $query = SiteAccess::current()->scope($relation->getQuery());

        $count = $query->count();

        if ($count === 0) {
            return null;
        }

        return (string) $count;
    }
}
