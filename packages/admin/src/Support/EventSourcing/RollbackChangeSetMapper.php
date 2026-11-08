<?php

declare(strict_types=1);

namespace Capell\Admin\Support\EventSourcing;

use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\RollbackFieldChangeData;
use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;
use Capell\Core\EventSourcing\Rollback\VersionComparisonData;

/**
 * Adapts core's package-neutral rollback diff onto the admin activity-diff DTO
 * so the existing ActivityChangeDetailsPresenter renders it — the admin diff
 * renderer is reused, and core never depends on admin.
 */
final class RollbackChangeSetMapper
{
    public function toChangeSet(RollbackPreviewData $preview, string $summary, string $actorLabel): ActivityChangeSetData
    {
        $fields = array_map($this->changedField(...), $preview->fields);

        return new ActivityChangeSetData(
            summary: $summary,
            resource: null,
            fields: $fields,
            actorLabel: $actorLabel,
            event: 'updated',
            occurredAt: null,
            workspaceId: null,
            emptyMessage: $preview->hasChanges()
                ? null
                : (string) __('capell-admin::event-sourcing.rollback_no_changes'),
        );
    }

    public function comparisonToChangeSet(VersionComparisonData $comparison, string $summary, string $actorLabel): ActivityChangeSetData
    {
        return new ActivityChangeSetData(
            summary: $summary,
            resource: null,
            fields: array_map($this->changedField(...), $comparison->fields),
            actorLabel: $actorLabel,
            event: 'updated',
            occurredAt: null,
            workspaceId: null,
            emptyMessage: $comparison->hasChanges()
                ? null
                : (string) __('capell-admin::event-sourcing.compare_no_changes'),
        );
    }

    private function changedField(RollbackFieldChangeData $change): ActivityChangedFieldData
    {
        return new ActivityChangedFieldData(
            path: $change->path,
            beforeValue: $change->before,
            afterValue: $change->after,
            status: $this->status($change->changeType),
            reversible: true,
            label: $change->label,
        );
    }

    private function status(RollbackChangeType $changeType): string
    {
        return match ($changeType) {
            RollbackChangeType::Added => 'created',
            RollbackChangeType::Removed => 'deleted',
            default => 'updated',
        };
    }
}
