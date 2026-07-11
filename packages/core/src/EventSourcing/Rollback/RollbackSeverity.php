<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

/**
 * Severity of a rollback validation issue. Blocking issues prevent apply;
 * warnings are surfaced in the preview but do not stop the operation.
 */
enum RollbackSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
}
