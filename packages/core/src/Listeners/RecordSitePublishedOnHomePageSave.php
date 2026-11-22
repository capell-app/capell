<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\FirstRun\ReconcileSitePublicationMilestonesAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Page;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;

/**
 * A site goes live when a save changes its home page's visibility and leaves
 * it public, without a publication transition: a form that sets the dates, or
 * an import that updates the page. A page created public is left to the
 * scheduled reconcile, because a first install creates its placeholder home
 * page public before it marks the site as fresh.
 */
final class RecordSitePublishedOnHomePageSave
{
    /** @var list<string> */
    private const array VISIBILITY_ATTRIBUTES = ['visible_from', 'visible_until', 'meta', 'deleted_at'];

    public function handle(PageSaved $event): void
    {
        $page = $event->page;

        if (! $page instanceof Page
            || ! $page->wasChanged(self::VISIBILITY_ATTRIBUTES)
            || ! SitePublicationMilestones::isPublicHome($page)) {
            return;
        }

        // The reconcile checks that the page is a home page and that its site has no go-live date yet.
        ReconcileSitePublicationMilestonesAction::run((int) $page->site_id);
    }
}
