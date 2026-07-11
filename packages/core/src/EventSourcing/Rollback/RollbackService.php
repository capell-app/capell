<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Capell\Core\EventSourcing\Contracts\CarriesAggregateState;
use Capell\Core\EventSourcing\Contracts\CarriesRollbackOrigin;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;
use Capell\Core\EventSourcing\Exceptions\RollbackBlocked;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;

/**
 * The reusable rollback engine: reconstitute an aggregate's state at a chosen
 * version *without writing*, build a preview (diff + validation), and — only on
 * explicit apply — restore the owned relations and record the rollback as a new
 * forward event. History is never rewritten.
 */
final class RollbackService
{
    public function __construct(
        private readonly RollbackValidatorRegistry $validatorRegistry,
        private readonly StateDiffer $differ,
        private readonly StoredEventRepository $storedEventRepository,
    ) {}

    public function buildPreview(Model&EventSourced $model, int $toVersion): RollbackPreviewData
    {
        $targetState = $this->targetStateAt($model->aggregateUuid(), $toVersion);
        $currentState = $model->eventSourcedSerializer()->capture($model);

        return new RollbackPreviewData(
            toVersion: $toVersion,
            currentVersion: $this->currentVersion($model->aggregateUuid()),
            fields: $this->differ->diff($currentState, $targetState),
            issues: $this->validate($model, $targetState),
            targetState: $targetState,
        );
    }

    /**
     * Apply a rollback. Re-validates first, then in a single transaction
     * restores owned relations and appends a rollback marker event.
     */
    public function apply(Model&EventSourced $model, int $toVersion): RollbackPreviewData
    {
        $preview = $this->buildPreview($model, $toVersion);

        if ($preview->isBlocked()) {
            throw new RollbackBlocked($preview->blockingIssues());
        }

        $aggregateClass = $model->eventSourcedAggregate();
        $uuid = $model->aggregateUuid();
        $targetState = $preview->targetState;

        DB::transaction(function () use ($model, $aggregateClass, $uuid, $toVersion, $targetState): void {
            $model->eventSourcedSerializer()->restore($model, $targetState);

            $aggregateClass::retrieve($uuid)
                ->recordRollback($toVersion, $targetState)
                ->persist();
        });

        return $preview;
    }

    /**
     * The most recent serialised state at or before the given version. Because
     * content is state-stored (no per-field upcasting), the target is simply
     * the latest state-bearing event in range — no fold required.
     *
     * @return array<string, mixed>
     */
    public function targetStateAt(string $uuid, int $toVersion): array
    {
        $state = null;

        // Walk the stored events at or before the target version, newest first,
        // and stop at the first state-bearing one. Because every revision and
        // rollback event carries full state, this resolves on the first row in
        // the common case (toVersion is itself state-bearing) instead of
        // deserialising the entire — possibly large — event stream.
        /** @var class-string<EloquentStoredEvent> $storedEventModel */
        $storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);

        foreach (
            $storedEventModel::query()
                ->where('aggregate_uuid', $uuid)
                ->where('aggregate_version', '<=', $toVersion)
                ->orderByDesc('aggregate_version')
                ->cursor() as $record
        ) {
            $event = $record->toStoredEvent()->event;

            if ($event instanceof CarriesAggregateState) {
                $state = $event->state();
                break;
            }
        }

        if ($state === null) {
            throw new EventSourcingException(sprintf(
                'No recorded state at or before version %d for aggregate %s.',
                $toVersion,
                $uuid,
            ));
        }

        return $state;
    }

    public function currentVersion(string $uuid): int
    {
        return $this->storedEventRepository->getLatestAggregateVersion($uuid);
    }

    /**
     * Which version's content is *live* right now. Normally that is the head
     * version, but when the head event is a rollback the live content mirrors
     * the version that rollback restored ({@see CarriesRollbackOrigin}). The
     * admin uses this to decide whether restoring a given version moves content
     * backward (roll back) or forward (redo of undone content). Returns 0 when
     * the aggregate has no events yet.
     */
    public function activeContentVersion(string $uuid): int
    {
        $headVersion = $this->currentVersion($uuid);

        if ($headVersion === 0) {
            return 0;
        }

        // Inspect only the single head event — no fold required, since the
        // rollback origin (if any) is recorded on the latest event itself.
        /** @var class-string<EloquentStoredEvent> $storedEventModel */
        $storedEventModel = config('event-sourcing.stored_event_model', EloquentStoredEvent::class);

        $record = $storedEventModel::query()
            ->where('aggregate_uuid', $uuid)
            ->orderByDesc('aggregate_version')
            ->first();

        if ($record === null) {
            return $headVersion;
        }

        $event = $record->toStoredEvent()->event;

        return $event instanceof CarriesRollbackOrigin
            ? $event->rolledBackToVersion()
            : $headVersion;
    }

    /**
     * @param  array<string, mixed>  $targetState
     * @return list<RollbackIssueData>
     */
    private function validate(Model&EventSourced $model, array $targetState): array
    {
        $issues = [];

        foreach ($this->validatorRegistry->for($model) as $validator) {
            foreach ($validator->validate($model, $targetState) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
