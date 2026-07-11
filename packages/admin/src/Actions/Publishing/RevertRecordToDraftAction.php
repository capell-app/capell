<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Actions\Publishing\Concerns\NormalisesPublishDates;
use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Reverts any publishable record to draft by writing the far-future draft
 * sentinel to `visible_from`, taking it off the public frontend without
 * scheduling a real future publish.
 */
final class RevertRecordToDraftAction
{
    use AsObject;
    use NormalisesPublishDates;

    public function handle(Model&Publishable $record, User $actor): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (PagePublishSentinel::isDraftValue($this->dateAttribute($record, 'visible_from'))) {
            return PublishVisibilityActionResultData::skipped('already_draft');
        }

        $sentinel = PagePublishSentinel::draftValue();
        $record->setAttribute('visible_from', $sentinel);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_from' => $sentinel->toDateTimeString(),
            'reverted_to_draft_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
