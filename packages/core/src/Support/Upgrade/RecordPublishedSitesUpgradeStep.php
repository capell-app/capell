<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Records sites that were already live before Capell tracked when a site goes
 * live, so an existing site never drops back into the first-run dashboard if
 * its home page is unpublished later. Safe to run again.
 */
final class RecordPublishedSitesUpgradeStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.record-published-sites';
    }

    public function label(): string
    {
        return 'Record which sites are already live';
    }

    /** Only when a site with a home page has no go-live date yet. */
    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        return $this->unrecordedHomePages()->exists();
    }

    public function run(UpgradeContext $context): bool
    {
        $milestones = resolve(SitePublicationMilestones::class);
        $now = CarbonImmutable::now();

        $this->unrecordedHomePages()->with('site')->orderBy('id')->each(function (Page $home) use ($milestones, $now): void {
            // A first install's untouched placeholder home page is public before anyone publishes the site.
            if (SitePublicationMilestones::isPublicHome($home, $now)
                && (! $home->site instanceof Site || ! ResolveGuidedSetupStateAction::isPlaceholderHome($home->site, $home))) {
                $milestones->record((int) $home->site_id, $now);
            }
        });

        return true;
    }

    /** The milestones only stop an established site showing first-run guidance; there is nothing to undo. */
    #[Override]
    public function rollback(UpgradeContext $context): bool
    {
        return true;
    }

    /** @return Builder<Page> */
    private function unrecordedHomePages(): Builder
    {
        $published = resolve(SitePublicationMilestones::class)->publishedSiteIds();

        return Page::query()->homePage()
            ->when($published !== [], fn (Builder $query): Builder => $query->whereNotIn('site_id', $published));
    }
}
