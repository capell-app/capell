<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Aggregates;

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
use Carbon\CarbonImmutable;

/**
 * The first adopter of the event-sourcing foundation: a composite aggregate
 * over a page row, its translations/content, pageUrls, meta/media and editorial
 * workflow.
 *
 * Invariants the aggregate uniquely enforces: a page cannot be approved twice,
 * cannot be scheduled in the past, cannot be published from archived,
 * changes-requested requires a note, and transitions follow the state machine
 * declared on PageWorkflowStatus.
 *
 * `$status` is stored as the backing string (not the enum) so Spatie aggregate
 * snapshots round-trip cleanly through JSON.
 */
final class PageAggregate extends CapellAggregateRoot
{
    public string $status = PageWorkflowStatus::Draft->value;

    public function currentStatus(): PageWorkflowStatus
    {
        return PageWorkflowStatus::from($this->status);
    }

    public function recordRevision(array $state): static
    {
        $event = new PageRevisionRecorded($state);
        $event->setMetaData($this->actorMetaData());

        return $this->recordThat($event);
    }

    public function recordRollback(int $toVersion, array $state): static
    {
        $event = new PageRolledBack($toVersion, $state);
        $event->setMetaData($this->actorMetaData());

        return $this->recordThat($event);
    }

    public function submitForReview(): static
    {
        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::InReview),
            'A page can only be submitted for review from draft or changes-requested.',
        );

        return $this->recordWorkflow(new PageSubmittedForReview);
    }

    public function approve(): static
    {
        $this->guard(
            $this->currentStatus() === PageWorkflowStatus::InReview,
            'Only a page that is in review can be approved.',
        );

        return $this->recordWorkflow(new PageApproved);
    }

    public function requestChanges(string $note): static
    {
        $this->guard(
            trim($note) !== '',
            'Requesting changes requires a note explaining what to change.',
        );

        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::ChangesRequested),
            'Changes can only be requested on a page that is in review or approved.',
        );

        return $this->recordWorkflow(new PageChangesRequested($note));
    }

    public function schedulePublish(CarbonImmutable $at): static
    {
        $this->guard(
            $at->isFuture(),
            'A page cannot be scheduled to publish in the past.',
        );

        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::Scheduled),
            'This page cannot be scheduled from its current state.',
        );

        return $this->recordWorkflow(new PagePublishScheduled($at));
    }

    public function publishNow(): static
    {
        $this->guard(
            $this->currentStatus() !== PageWorkflowStatus::Archived,
            'An archived page must be returned to draft before it can be published.',
        );

        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::Published),
            'This page cannot be published from its current state.',
        );

        return $this->recordWorkflow(new PagePublishedNow);
    }

    public function unpublish(): static
    {
        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::Unpublished),
            'Only a live or scheduled page can be unpublished.',
        );

        return $this->recordWorkflow(new PageUnpublished);
    }

    public function archive(): static
    {
        $this->guard(
            $this->currentStatus()->canTransitionTo(PageWorkflowStatus::Archived),
            'This page cannot be archived from its current state.',
        );

        return $this->recordWorkflow(new PageArchived);
    }

    protected function applySubmittedForReview(PageSubmittedForReview $event): void
    {
        $this->status = PageWorkflowStatus::InReview->value;
    }

    protected function applyApproved(PageApproved $event): void
    {
        $this->status = PageWorkflowStatus::Approved->value;
    }

    protected function applyChangesRequested(PageChangesRequested $event): void
    {
        $this->status = PageWorkflowStatus::ChangesRequested->value;
    }

    protected function applyPublishScheduled(PagePublishScheduled $event): void
    {
        $this->status = PageWorkflowStatus::Scheduled->value;
    }

    protected function applyPublishedNow(PagePublishedNow $event): void
    {
        $this->status = PageWorkflowStatus::Published->value;
    }

    protected function applyUnpublished(PageUnpublished $event): void
    {
        $this->status = PageWorkflowStatus::Unpublished->value;
    }

    protected function applyArchived(PageArchived $event): void
    {
        $this->status = PageWorkflowStatus::Archived->value;
    }

    private function recordWorkflow(PageSubmittedForReview|PageApproved|PageChangesRequested|PagePublishScheduled|PagePublishedNow|PageUnpublished|PageArchived $event): static
    {
        $event->setMetaData($this->actorMetaData());

        return $this->recordThat($event);
    }
}
