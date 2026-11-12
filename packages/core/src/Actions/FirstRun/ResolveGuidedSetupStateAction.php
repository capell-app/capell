<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Data\FirstRun\GuidedSetupStateData;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Guided setup is owed to a site the actor can reach that is still exactly as the installer
 * left it: nobody has set it up, skipped it, imported a site spec into it or put it live, and
 * it holds nothing beyond the installer's home page. Anything else is a site someone is
 * already building or running, so an upgraded or half-built install is never sent to a setup
 * screen that would write over its pages.
 */
final class ResolveGuidedSetupStateAction
{
    use AsFake;
    use AsObject;

    public const string REASON_SET_UP = 'set_up';

    public const string REASON_EXISTING_WORK = 'existing_work';

    /**
     * Whether the site has been set up: guided setup finished, or a site spec was imported
     * into it. The first-run checklist's "Set up your site" task reads the same fact, so the
     * two can never disagree about it. Skipping is not setting up.
     */
    public static function isSetUp(Site $site): bool
    {
        $meta = $site->meta ?? [];

        return filled(data_get($meta, 'guided_setup.completed_at'))
            || filled($meta['site_spec_hash'] ?? null)
            || filled($meta['site_spec_applied_hash'] ?? null);
    }

    /** Whether guided setup may write to this site right now. Used to offer it and again before it writes. */
    public static function isOwedTo(Site $site): bool
    {
        return self::refusalFor($site) === null;
    }

    /** @return self::REASON_*|null why guided setup must not write to the site, or null when it may */
    public static function refusalFor(Site $site): ?string
    {
        if (self::isSetUp($site)
            || filled(data_get($site->meta, 'guided_setup.skipped_at'))
            || resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey())) {
            return self::REASON_SET_UP;
        }

        return self::hasExistingWork($site) ? self::REASON_EXISTING_WORK : null;
    }

    /**
     * The site holds something beyond the installer's home page: another page, or a home page
     * a person has edited. Creating the preset's pages would write over it.
     */
    public static function hasExistingWork(Site $site): bool
    {
        if (Page::query()->where('site_id', $site->getKey())->notHomePage()->exists()) {
            return true;
        }

        return PageRevision::query()
            ->whereIn('page_uuid', Page::query()->where('site_id', $site->getKey())->homePage()->select('uuid'))
            ->whereNotNull('actor_id')
            ->where('is_rollback', false)
            ->where(fn (Builder $query): Builder => $query->whereNull('source')->orWhere('source', PageVersionSource::Edit->value))
            ->exists();
    }

    public function handle(Authenticatable $actor): GuidedSetupStateData
    {
        return resolve(GuidedSetupStateMemo::class)->remember(
            (string) $actor->getAuthIdentifier(),
            fn (): GuidedSetupStateData => $this->build($actor),
        );
    }

    /** The site whose pages guided setup created but the person has not published yet, so they can come back to publish. */
    public function awaitingPublication(Authenticatable $actor): ?Site
    {
        $site = SiteAccess::forActor($actor)->query(Site::class)->excludingPreview()->orderBy('id')->first();

        if (! $site instanceof Site || blank(data_get($site->meta, 'guided_setup.completed_at'))) {
            return null;
        }

        return $this->isLive($site) ? null : $site;
    }

    private function build(Authenticatable $actor): GuidedSetupStateData
    {
        $site = SiteAccess::forActor($actor)->query(Site::class)->excludingPreview()->orderBy('id')->first();

        if (! $site instanceof Site || filled(data_get($site->meta, 'guided_setup.skipped_at')) || ! self::isOwedTo($site)) {
            return GuidedSetupStateData::notNeeded();
        }

        return $this->isLive($site) ? GuidedSetupStateData::notNeeded() : GuidedSetupStateData::forSite($site);
    }

    /** A site is live once its go-live is recorded or one of its home pages is public now. */
    private function isLive(Site $site): bool
    {
        if (resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey())) {
            return true;
        }

        return Page::query()->where('site_id', $site->getKey())->homePage()->published()->exists();
    }
}
