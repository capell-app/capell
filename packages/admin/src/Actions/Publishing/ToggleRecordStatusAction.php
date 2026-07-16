<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Contracts\Statusable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Flips a {@see Statusable} record's Active/Inactive status. Decoupled from the
 * publish lifecycle: a record can be Active but scheduled, or Inactive but live.
 * Records history when the model is also publishable.
 */
final class ToggleRecordStatusAction
{
    use AsFake;
    use AsObject;

    public function handle(Model&Statusable $record, User $actor, ?bool $enabled = null): PublishVisibilityActionResultData
    {
        if (! Gate::forUser($actor)->allows('update', $record)) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        $target = $enabled ?? ! $record->isEnabled();

        if ($target === $record->isEnabled()) {
            return PublishVisibilityActionResultData::skipped('unchanged');
        }

        $record->setAttribute('status', $target);
        $record->save();

        if ($record instanceof Publishable) {
            RecordPublishHistoryAction::run($record, [
                'status' => $target,
                'status_changed_by' => $actor->getKey(),
            ]);
        }

        return PublishVisibilityActionResultData::changed();
    }
}
