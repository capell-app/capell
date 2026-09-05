<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Actions\Properties\SyncPromotedFieldValuesAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Page;

/**
 * Bridges the existing `PageSaved` domain event to
 * {@see SyncPromotedFieldValuesAction}, so a promoted property's value stays
 * in step with its source field on every ordinary page save.
 *
 * Recursion guard: {@see SetPagePropertyValuesAction}
 * itself dispatches `PageSaved` after writing values (so the write rides the
 * normal revision-recording bridge). It marks that dispatch with a
 * `_property_keys` formData key; this listener skips those saves so a
 * property write can never trigger a resync of itself.
 */
final class SyncPromotedPropertyFieldsOnPageSaved
{
    public function handle(PageSaved $event): void
    {
        if (array_key_exists('_property_keys', $event->formData)) {
            return;
        }

        if (! $event->page instanceof Page) {
            return;
        }

        SyncPromotedFieldValuesAction::run($event->page);
    }
}
