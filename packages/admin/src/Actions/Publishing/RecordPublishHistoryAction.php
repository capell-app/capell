<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Events\PublishableRecordSaved;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Contracts\Publishable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records publish-state history for any publishable record changed by the
 * publish panel.
 *
 * Page carries event-sourced history via {@see PageSavedAction}/`PageSaved`, so
 * Pageable records route there to preserve the existing timeline. Every other
 * publishable model gets a generic {@see PublishableRecordSaved} event its owning
 * package can listen to for its own activity log.
 */
final class RecordPublishHistoryAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(Model&Publishable $record, array $metadata): void
    {
        if ($record instanceof Pageable) {
            PageSavedAction::run($record, $metadata);

            return;
        }

        event(new PublishableRecordSaved($record, $metadata));
    }
}
