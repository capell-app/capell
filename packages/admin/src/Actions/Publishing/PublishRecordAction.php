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
 * Publishes any publishable record immediately: sets `visible_from` to now
 * (clearing a draft sentinel or future schedule) and lifts a past `visible_until`
 * expiry, so the record is genuinely live. Polymorphic sibling of the Pages bulk
 * publish flow — used by the shared publish panel for every resource.
 */
final class PublishRecordAction
{
    use AsObject;

    public function handle(Model&Publishable $record, User $actor): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! $record->isPending() && ! $record->isExpired()) {
            return PublishVisibilityActionResultData::skipped('already_published');
        }

        $now = CarbonImmutable::now();

        if ($record->isExpired()) {
            $record->setAttribute('visible_until', null);
        }

        $record->setAttribute('visible_from', $now);
        $record->save();

        RecordPublishHistoryAction::run($record, [
            'visible_from' => $now->toDateTimeString(),
            'published_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
