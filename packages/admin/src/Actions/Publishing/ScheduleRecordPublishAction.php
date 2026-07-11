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
 * Schedules any publishable record to go live at a future `visible_from`. The
 * date must be in the future and fall before any existing `visible_until` expiry.
 */
final class ScheduleRecordPublishAction
{
    use AsObject;
    use NormalisesPublishDates;

    public function handle(Model&Publishable $record, User $actor, CarbonImmutable $publishAt): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! $publishAt->isFuture()) {
            return PublishVisibilityActionResultData::skipped('not_future');
        }

        $visibleUntil = $this->dateAttribute($record, 'visible_until');

        if ($visibleUntil instanceof CarbonImmutable && $publishAt->greaterThanOrEqualTo($visibleUntil)) {
            return PublishVisibilityActionResultData::skipped('after_unpublish');
        }

        $record->setAttribute('visible_from', $publishAt);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_from' => $publishAt->toDateTimeString(),
            'scheduled_publish_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
