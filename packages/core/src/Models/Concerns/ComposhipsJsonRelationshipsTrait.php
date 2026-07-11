<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Awobaz\Compoships\Compoships;
use Staudenmeir\EloquentJsonRelations\HasJsonRelationships;

trait ComposhipsJsonRelationshipsTrait
{
    use Compoships, HasJsonRelationships {
        Compoships::getAttribute as composhipsGetAttribute;
        HasJsonRelationships::getAttribute as hasJsonRelationshipsGetAttribute;

        Compoships::getAttribute insteadof HasJsonRelationships;
        Compoships::newBelongsTo insteadof HasJsonRelationships;
        Compoships::newHasMany insteadof HasJsonRelationships;
        Compoships::newHasOne insteadof HasJsonRelationships;
    }

    public function getAttribute($key)
    {
        if (is_array($key)) {
            return $this->composhipsGetAttribute($key);
        }

        return $this->hasJsonRelationshipsGetAttribute($key);
    }
}
