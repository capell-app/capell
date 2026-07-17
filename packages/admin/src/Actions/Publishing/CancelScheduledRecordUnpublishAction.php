<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Actions\Publishing\CancelScheduledUnpublishAction;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Clears a future `visible_until` on any publishable record, keeping it live by
 * removing the scheduled unpublish. No-op when no future expiry is set.
 */
final class CancelScheduledRecordUnpublishAction
{
    use AsFake;
    use AsObject;

    public function handle(Model&Publishable $record, User $actor): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! CancelScheduledUnpublishAction::run($record, CarbonImmutable::now())) {
            return PublishVisibilityActionResultData::skipped('not_scheduled');
        }

        RecordPublishHistoryAction::run($record, [
            'visible_until' => null,
            'cancelled_scheduled_unpublish_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
