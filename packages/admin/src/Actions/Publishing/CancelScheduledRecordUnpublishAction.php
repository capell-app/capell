<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Actions\Publishing\Concerns\NormalisesPublishDates;
use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Clears a future `visible_until` on any publishable record, keeping it live by
 * removing the scheduled unpublish. No-op when no future expiry is set.
 */
final class CancelScheduledRecordUnpublishAction
{
    use AsObject;
    use NormalisesPublishDates;

    public function handle(Model&Publishable $record, User $actor): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! $this->dateAttribute($record, 'visible_until')?->isFuture()) {
            return PublishVisibilityActionResultData::skipped('not_scheduled');
        }

        $record->setAttribute('visible_until', null);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_until' => null,
            'cancelled_scheduled_unpublish_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
