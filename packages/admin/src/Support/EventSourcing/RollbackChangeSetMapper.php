<?php

declare(strict_types=1);

namespace Capell\Admin\Support\EventSourcing;

use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\RollbackFieldChangeData;
use Capell\Core\EventSourcing\Rollback\RollbackPreviewData;

/**
 * Adapts core's package-neutral rollback diff onto the admin activity-diff DTO
 * so the existing ActivityChangeDetailsPresenter renders it — the admin diff
 * renderer is reused, and core never depends on admin.
 */
final class RollbackChangeSetMapper
{
    public function toChangeSet(RollbackPreviewData $preview, string $summary, string $actorLabel): ActivityChangeSetData
    {
        $fields = array_map(
            fn (RollbackFieldChangeData $change): ActivityChangedFieldData => new ActivityChangedFieldData(
                path: $change->path,
                beforeValue: $change->before,
                afterValue: $change->after,
                status: $this->status($change->changeType),
                reversible: true,
                skipReason: null,
                label: $change->label,
            ),
            $preview->fields,
        );

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

    private function status(RollbackChangeType $changeType): string
    {
        return match ($changeType) {
            RollbackChangeType::Added => 'created',
            RollbackChangeType::Removed => 'deleted',
            default => 'updated',
        };
    }
}
