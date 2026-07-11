<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Actions;

use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Builds a non-destructive rollback preview: replays the stream to the chosen
 * version in memory, diffs it against the current model, and runs the
 * registered validators. No database writes occur.
 */
final class BuildRollbackPreviewAction
{
    use AsObject;

    public function __construct(
        private readonly RollbackService $rollbackService,
    ) {}

    public function handle(Model&EventSourced $model, int $toVersion): RollbackPreviewData
    {
        return $this->rollbackService->buildPreview($model, $toVersion);
    }
}
