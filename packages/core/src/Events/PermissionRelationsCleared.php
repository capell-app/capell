<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Illuminate\Database\Eloquent\Model;

/** Loaded permission relations were cleared, including on an already unloaded actor. */
final readonly class PermissionRelationsCleared
{
    public function __construct(public Model $actor) {}
}
