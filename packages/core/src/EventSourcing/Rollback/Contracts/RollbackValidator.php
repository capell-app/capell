<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Contracts;

use Capell\Core\EventSourcing\Rollback\RollbackIssueData;
use Illuminate\Database\Eloquent\Model;

/**
 * Inspects a proposed rollback target state against current database reality
 * and returns any issues (uniqueness collisions, dangling references, …).
 * Pluggable per aggregate via the RollbackValidatorRegistry.
 */
interface RollbackValidator
{
    /**
     * @param  array<string, mixed>  $targetState
     * @return list<RollbackIssueData>
     */
    public function validate(Model $model, array $targetState): array;
}
