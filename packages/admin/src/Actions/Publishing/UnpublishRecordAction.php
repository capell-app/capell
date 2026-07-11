<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Takes any live publishable record offline immediately by setting
 * `visible_until` to now. No-op when the record is already pending or expired.
 */
final class UnpublishRecordAction
{
    use AsObject;

    public function handle(Model&Publishable $record, User $actor): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if ($record->isExpired() || $record->isPending()) {
            return PublishVisibilityActionResultData::skipped('not_live');
        }

        $now = CarbonImmutable::now();
        $record->setAttribute('visible_until', $now);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_until' => $now->toDateTimeString(),
            'unpublished_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
