<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

/**
 * Classifies how a single state section differs between the current model and
 * the rollback target.
 */
enum RollbackChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';
    case Unchanged = 'unchanged';
}
