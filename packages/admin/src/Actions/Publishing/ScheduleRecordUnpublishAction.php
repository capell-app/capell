<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Actions\Publishing\Concerns\NormalisesPublishDates;
use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Schedules any publishable record to come offline at a future `visible_until`.
 * The date must be in the future and fall after any `visible_from` publish date.
 */
final class ScheduleRecordUnpublishAction
{
    use AsObject;
    use NormalisesPublishDates;

    public function handle(Model&Publishable $record, User $actor, CarbonImmutable $unpublishAt): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! $unpublishAt->isFuture()) {
            return PublishVisibilityActionResultData::skipped('not_future');
        }

        $visibleFrom = $this->dateAttribute($record, 'visible_from');

        if ($visibleFrom instanceof CarbonImmutable && $unpublishAt->lessThanOrEqualTo($visibleFrom)) {
            return PublishVisibilityActionResultData::skipped('before_publish');
        }

        $record->setAttribute('visible_until', $unpublishAt);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_until' => $unpublishAt->toDateTimeString(),
            'scheduled_unpublish_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
