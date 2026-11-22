<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Install\InstallRunState;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records, dated by when the page became visible, the sites whose home page is
 * public but whose go-live was never recorded, because nothing announced it: a scheduled publish time passed, an
 * import or seeder wrote the page, or a person edited the placeholder home page
 * of a site whose setup was skipped. Runs on a schedule, after a save that
 * changes a home page's visibility and in the upgrade step, so no screen has to
 * write while it renders. Safe to run again: a recorded site is never touched.
 *
 * A first install makes its home page public before it marks the site as
 * fresh, so nothing is recorded while one is under way; otherwise a scheduler
 * tick in the middle of an install would put the new site live and guided
 * setup would never be offered.
 */
final class ReconcileSitePublicationMilestonesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  int|null  $siteId  only this site, or every site when null
     * @return int how many sites were recorded as live
     */
    public function handle(?int $siteId = null, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $milestones = resolve(SitePublicationMilestones::class);
        $installChecked = false;
        $recorded = 0;

        // By id, not by offset: recording a site removes its pages from the result.
        foreach ($this->unrecordedHomePages($siteId)->with('site')->lazyById(200) as $home) {
            if (! SitePublicationMilestones::isLiveHome($home, $now)) {
                continue;
            }

            if (! $installChecked) {
                if (InstallRunState::initialInstallationUnderway()) {
                    return 0;
                }

                $installChecked = true;
            }

            if ($milestones->record((int) $home->site_id, $this->wentPublicAt($home, $now))) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /** Whether any site with a home page still has no go-live date. */
    public function hasUnrecordedHomePages(): bool
    {
        return $this->unrecordedHomePages(null)->exists();
    }

    /**
     * The page's own start of visibility, so a scheduled publish is dated by its schedule and
     * a save inside a publication transition by that transition, not by when this ran.
     */
    private function wentPublicAt(Page $home, CarbonImmutable $now): CarbonImmutable
    {
        $from = $home->visible_from;

        return $from instanceof DateTimeInterface && $now->greaterThan($from) ? CarbonImmutable::instance($from) : $now;
    }

    /** @return Builder<Page> */
    private function unrecordedHomePages(?int $siteId): Builder
    {
        return Page::query()->homePage()
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->whereIn('site_id', Site::query()->whereNull('meta->first_run->published_at')->select('id'));
    }
}
