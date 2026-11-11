<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Events\PublicationTransitioned;
use Capell\Core\Models\Page;

/**
 * The site counts as published the first time a home page is made public
 * through a publication transition (the editor, the installer or a bulk publish).
 */
final class RecordSitePublishedOnHomePagePublication
{
    public function handle(PublicationTransitioned $event): void
    {
        $record = $event->request->record;

        if (! $event->result->changed()
            || $event->result->afterState !== PublishVisibilityStateEnum::published
            || ! $record instanceof Page
            || ! Page::query()->homePage()->whereKey($record->getKey())->exists()) {
            return;
        }

        RecordSitePublishedAction::run($event->request->now);
    }
}
