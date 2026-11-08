<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Actions;

use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\EventSourcing\Support\PageVersionContext;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records the current state of a page as a new version in the single versions
 * store, with its provenance. Packages that change page content outside the
 * editor (a workspace publish, an import) call this instead of keeping their
 * own revision table, so every version appears in one page history and can be
 * compared and restored through the same rollback engine.
 */
final class RecordPageVersionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly EventSourcedRegistry $registry,
        private readonly PageVersionContext $context,
        private readonly RollbackService $rollbackService,
    ) {}

    public function handle(Model&EventSourced $model, PageVersionSource $source, ?string $label = null): int
    {
        throw_if(
            $source === PageVersionSource::Rollback,
            InvalidArgumentException::class,
            'Rollbacks are recorded by the rollback engine, not as a page version.',
        );

        throw_unless(
            $this->registry->isRegistered($model),
            InvalidArgumentException::class,
            sprintf('[%s] is not registered for event sourcing.', $model::class),
        );

        return $this->context->run($source, $label, function () use ($model): int {
            $this->registry->aggregateFor($model)::retrieve($model->aggregateUuid())
                ->recordRevision($this->registry->serializerFor($model)->capture($model))
                ->persist();

            return $this->rollbackService->currentVersion($model->aggregateUuid());
        });
    }
}
