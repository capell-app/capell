<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\EventSourcing\Contracts\EventSourced;
use Capell\Core\EventSourcing\Exceptions\RollbackBlocked;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\PageRevision;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Routes activity-log revert for event-sourced subjects into a complete event-
 * sourcing rollback. The Activity UI's existing revert button thus performs a
 * full, multi-relation page rollback (to the revision captured at or before the
 * reverted activity) with no UI change, instead of the default field-by-field
 * reapply.
 *
 * Version resolution currently reads the page_revisions index, so this handler
 * is Page-aware; a future adopter (Layout/Blueprint) would generalise it.
 */
final class EventSourcedActivityRevertHandler implements ActivityRevertHandler
{
    public function __construct(
        private readonly EventSourcedRegistry $registry,
    ) {}

    public function supports(ActivityRevertSelectionData $selection): bool
    {
        $subjectClass = $selection->subjectClass;

        return $subjectClass !== null
            && is_a($subjectClass, EventSourced::class, true)
            && $this->registry->isRegistered($subjectClass);
    }

    public function priority(): int
    {
        // Above the default handler (0) so event-sourced subjects route here.
        return 100;
    }

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData
    {
        $subjectClass = $selection->subjectClass;

        if ($subjectClass === null) {
            return ActivityRevertResultData::failed('capell-admin::event-sourcing.revert_subject_missing');
        }

        $model = $subjectClass::query()->find($selection->subjectId);

        if (! $model instanceof Model || ! $model instanceof EventSourced) {
            return ActivityRevertResultData::failed('capell-admin::event-sourcing.revert_subject_missing');
        }

        // Reverting an activity here performs a full page rollback, so it must
        // require page.rollback in its own right — not merely activity_log.revert
        // (the permission gating the Activity UI's revert button).
        if (auth()->user()?->can(CapellPermission::RollbackPage->name()) !== true) {
            return ActivityRevertResultData::failed('capell-admin::event-sourcing.rollback_forbidden');
        }

        $version = $this->resolveVersion($model, $selection->activityId);

        if ($version === null) {
            return ActivityRevertResultData::failed('capell-admin::event-sourcing.revert_no_revision');
        }

        try {
            ApplyRollbackAction::run($model, $version);
        } catch (RollbackBlocked) {
            return ActivityRevertResultData::failed('capell-admin::event-sourcing.rollback_blocked');
        }

        return ActivityRevertResultData::success('capell-admin::event-sourcing.rollback_done');
    }

    private function resolveVersion(Model&EventSourced $model, int|string $activityId): ?int
    {
        $activityModel = config('activitylog.activity_model', Activity::class);
        $occurredAt = $activityModel::query()->find($activityId)?->created_at;

        $query = PageRevision::query()->where('page_uuid', $model->aggregateUuid());

        if ($occurredAt !== null) {
            $query->where('occurred_at', '<=', $occurredAt);
        }

        $version = $query->max('version');

        return $version === null ? null : (int) $version;
    }
}
