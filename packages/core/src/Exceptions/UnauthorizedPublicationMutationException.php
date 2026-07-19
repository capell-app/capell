<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class UnauthorizedPublicationMutationException extends RuntimeException
{
    public static function for(Model $model): self
    {
        $dirtyKeys = array_values(array_intersect(
            array_keys($model->getDirty()),
            ['visible_from', 'visible_until'],
        ));

        return new self(sprintf(
            'Unauthorized publication-date mutation on %s for [%s]. Use %s for publication changes.',
            $model::class,
            implode(', ', $dirtyKeys),
            TransitionPublicationAction::class,
        ));
    }
}
