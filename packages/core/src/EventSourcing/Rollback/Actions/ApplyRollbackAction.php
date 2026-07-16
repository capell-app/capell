<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Actions;

use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Applies a rollback: re-validates, then in a single transaction restores the
 * page + its owned relations and appends a PageRolledBack event (append-only —
 * history is never rewritten). Throws RollbackBlocked if a blocking validation
 * issue is present. Returns the preview that was applied.
 */
final class ApplyRollbackAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RollbackService $rollbackService,
    ) {}

    public function handle(Model&EventSourced $model, int $toVersion): RollbackPreviewData
    {
        return $this->rollbackService->apply($model, $toVersion);
    }
}
