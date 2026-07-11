<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Exceptions;

use Capell\Core\EventSourcing\Rollback\RollbackIssueData;

/**
 * Thrown when a rollback is applied while at least one blocking validation
 * issue (uniqueness, referential integrity, …) is unresolved.
 */
class RollbackBlocked extends EventSourcingException
{
    /**
     * @param  list<RollbackIssueData>  $issues
     */
    public function __construct(
        public readonly array $issues = [],
        string $message = 'Rollback is blocked by unresolved validation issues.',
    ) {
        parent::__construct($message);
    }
}
