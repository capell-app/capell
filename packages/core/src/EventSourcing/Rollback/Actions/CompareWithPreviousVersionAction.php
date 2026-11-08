<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Actions;

use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Rollback\VersionComparisonData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Shows what one version of a page changed, compared with the version before
 * it. Versions are immutable, so this never writes.
 */
final class CompareWithPreviousVersionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RollbackService $rollbackService,
    ) {}

    public function handle(Model&EventSourced $model, int $version): VersionComparisonData
    {
        return $this->rollbackService->compareWithPrevious($model->aggregateUuid(), $version);
    }
}
