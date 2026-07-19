<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Projectors;

use Capell\Core\EventSourcing\Aggregates\CapellAggregateRoot;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\EventSourcing\Events\PageApproved;
use Capell\Core\EventSourcing\Events\PageArchived;
use Capell\Core\EventSourcing\Events\PageChangesRequested;
use Capell\Core\EventSourcing\Events\PagePublishedNow;
use Capell\Core\EventSourcing\Events\PagePublishScheduled;
use Capell\Core\EventSourcing\Events\PageRevisionRecorded;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\EventSourcing\Events\PageSubmittedForReview;
use Capell\Core\EventSourcing\Events\PageUnpublished;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Carbon\CarbonImmutable;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Maintains the event-sourcing read models for pages.
 *
 * Crucially this projector is REPLAY-SAFE: it only writes the lightweight,
 * convergent read models (page_revisions index, page_workflow_states) and
 * mirrors workflow intent onto the page's visible_from/visible_until so the
 * existing ResolvePagePublishStateAction keeps returning the right state. It
 * deliberately does NOT restore page content on rollback — that is a one-time
 * command side-effect (see RollbackService), because a full replay would
 * otherwise walk every page back through historical states.
 *
 * Page visibility columns are updated via a mass query update so no Eloquent
 * model events fire — projecting a publish never loops back into the recording
 * bridge.
 */
final class PageProjector extends Projector
{
    public function onPageRevisionRecorded(PageRevisionRecorded $event): void
    {
        $this->indexRevision($event, isRollback: false, summary: 'Revision recorded');
        $this->ensureWorkflowState($event);
    }

    public function onPageRolledBack(PageRolledBack $event): void
    {
        $this->indexRevision(
            $event,
            isRollback: true,
            summary: sprintf('Rolled back to version %d', $event->toVersion),
        );
    }

    public function onPageSubmittedForReview(PageSubmittedForReview $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::InReview->value,
            'submitted_at' => $event->createdAt(),
            'requested_changes_note' => null,
        ]);
    }

    public function onPageApproved(PageApproved $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::Approved->value,
            'approver_id' => $this->actorId($event),
        ]);
    }

    public function onPageChangesRequested(PageChangesRequested $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::ChangesRequested->value,
            'requested_changes_note' => $event->note,
        ]);
    }

    public function onPagePublishScheduled(PagePublishScheduled $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::Scheduled->value,
            'scheduled_for' => $event->at,
        ]);

        $this->syncVisibility($event, visibleFrom: $event->at, visibleUntil: null);
    }

    public function onPagePublishedNow(PagePublishedNow $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::Published->value,
            'scheduled_for' => null,
        ]);

        $this->syncVisibility($event, visibleFrom: $event->createdAt(), visibleUntil: null);
    }

    public function onPageUnpublished(PageUnpublished $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::Unpublished->value,
            'scheduled_for' => null,
        ]);

        $this->syncVisibility($event, visibleFrom: null, visibleUntil: null);
    }

    public function onPageArchived(PageArchived $event): void
    {
        $this->writeWorkflow($event, [
            'status' => PageWorkflowStatus::Archived->value,
            'scheduled_for' => null,
        ]);

        $this->syncVisibility($event, visibleFrom: null, visibleUntil: null);
    }

    private function indexRevision(ShouldBeStored $event, bool $isRollback, string $summary): void
    {
        PageRevision::query()->updateOrCreate(
            [
                'page_uuid' => $event->aggregateRootUuid(),
                'version' => $event->aggregateRootVersion(),
            ],
            [
                'actor_id' => $this->actorId($event),
                'summary' => $summary,
                'is_rollback' => $isRollback,
                'occurred_at' => $event->createdAt() ?? CarbonImmutable::now(),
            ],
        );
    }

    private function ensureWorkflowState(ShouldBeStored $event): void
    {
        PageWorkflowState::query()->updateOrCreate(
            ['page_uuid' => $event->aggregateRootUuid()],
            ['aggregate_version' => $event->aggregateRootVersion()],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeWorkflow(ShouldBeStored $event, array $attributes): void
    {
        PageWorkflowState::query()->updateOrCreate(
            ['page_uuid' => $event->aggregateRootUuid()],
            array_merge($attributes, ['aggregate_version' => $event->aggregateRootVersion()]),
        );
    }

    private function syncVisibility(ShouldBeStored $event, ?CarbonImmutable $visibleFrom, ?CarbonImmutable $visibleUntil): void
    {
        PublicationDateGuard::allow(
            static fn (): int => Page::query()
                ->where('uuid', $event->aggregateRootUuid())
                ->update([
                    'visible_from' => $visibleFrom,
                    'visible_until' => $visibleUntil,
                ]),
        );
    }

    private function actorId(ShouldBeStored $event): ?int
    {
        $actorId = $event->metaData()[CapellAggregateRoot::META_ACTOR_ID] ?? null;

        return is_int($actorId) ? $actorId : (is_numeric($actorId) ? (int) $actorId : null);
    }
}
