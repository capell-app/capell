<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Events\PublicationTransitioned;
use Capell\Core\Models\Page;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;

/**
 * A site goes live the first time one of its home pages is made public through
 * a publication transition (the editor, the installer or a bulk publish).
 * Home pages that become public any other way are recorded by a save that
 * changes their visibility or by the scheduled reconcile.
 */
final class RecordSitePublishedOnHomePagePublication
{
    public function handle(PublicationTransitioned $event): void
    {
        $record = $event->request->record;

        if (! $event->result->changed()
            || $event->result->afterState !== PublishVisibilityStateEnum::published
            || ! $record instanceof Page
            || ! SitePublicationMilestones::isPublicHome($record, $event->request->now)
            || ! Page::query()->homePage()->whereKey($record->getKey())->exists()) {
            return;
        }

        RecordSitePublishedAction::run((int) $record->site_id, $event->request->now);
    }
}
