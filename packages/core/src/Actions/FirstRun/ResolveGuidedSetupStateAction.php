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
use Capell\Core\Support\Publishing\PublishSentinel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Guided setup is owed to a site a first install made (the installer marks it once the install
 * has finished) that the actor can reach and that is still as the install left it: nobody has
 * set it up, skipped it, imported a site spec into it or put it live, and no person has made or
 * edited any of its pages. Pages that packages seed during the install do not count. A site
 * without the install's mark, such as one installed before the mark existed, is never offered
 * setup, so an upgraded or half-built install is never sent to a screen that writes its pages.
 */
final class ResolveGuidedSetupStateAction
{
    use AsFake;
    use AsObject;

    public const string REASON_SET_UP = 'set_up';

    public const string REASON_EXISTING_WORK = 'existing_work';

    public const string FRESH_INSTALL_META_KEY = 'guided_setup.fresh_install_at';

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

    /** Whether a first install made this site and marked it when the install finished. */
    public static function isFreshInstall(Site $site): bool
    {
        return filled(data_get($site->meta, self::FRESH_INSTALL_META_KEY));
    }

    /** Whether guided setup may write to this site right now. Used to offer it and again before it writes. */
    public static function isOwedTo(Site $site): bool
    {
        return self::refusalFor($site) === null;
    }

    /** @return self::REASON_*|null why guided setup must not write to the site, or null when it may */
    public static function refusalFor(Site $site): ?string
    {
        if (! self::isFreshInstall($site)
            || self::isSetUp($site)
            || filled(data_get($site->meta, 'guided_setup.skipped_at'))
            || self::isLive($site)) {
            return self::REASON_SET_UP;
        }

        return self::hasExistingWork($site) ? self::REASON_EXISTING_WORK : null;
    }

    /**
     * A person has made or edited one of the site's pages. Where a page came from does not
     * matter: pages the installer or a package seeded carry no person's revision.
     */
    public static function hasExistingWork(Site $site): bool
    {
        return self::personRevisions(Page::query()->where('site_id', $site->getKey())->select('uuid'))->exists();
    }

    /**
     * The public home page a first install leaves in place until setup publishes the site: the
     * very page the install recorded, which no person has edited since. Only the recorded page and
     * the person's own revisions are read, so the page's content, of any shape, is never inspected;
     * a failure to read either counts as "not the placeholder".
     */
    public static function isPlaceholderHome(Site $site, Page $home): bool
    {
        try {
            $uuid = data_get($site->meta, self::FRESH_INSTALL_META_KEY . '.home_uuid');

            return is_string($uuid)
                && hash_equals($uuid, (string) $home->uuid)
                && ! $home->trashed()
                && ! self::personRevisions(Page::query()->whereKey($home->getKey())->select('uuid'))->exists();
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /** The install's home page while it is still the placeholder setup may write over. */
    public static function placeholderHome(Site $site): ?Page
    {
        $uuid = data_get($site->meta, self::FRESH_INSTALL_META_KEY . '.home_uuid');
        $home = is_string($uuid) ? Page::query()->where('site_id', $site->getKey())->where('uuid', $uuid)->first() : null;

        return $home instanceof Page && self::isPlaceholderHome($site, $home) ? $home : null;
    }

    /** @return list<string> the pages guided setup created, recorded when it created them */
    public static function setupPageUuids(Site $site): array
    {
        $uuids = data_get($site->meta, 'guided_setup.page_uuids');

        return is_array($uuids) ? array_values(array_filter($uuids, is_string(...))) : [];
    }

    public static function isPrivateDraft(Page $page): bool
    {
        return ! $page->trashed()
            && $page->visible_until === null
            && PublishSentinel::isDraftValue($page->visible_from);
    }

    /**
     * The pages setup created that nobody has touched since: still private drafts, never edited,
     * published, scheduled or reverted by a person, and not deleted. Setup publishes only these.
     *
     * @return list<Page>
     */
    public static function untouchedSetupPages(Site $site): array
    {
        $uuids = self::setupPageUuids($site);

        if ($uuids === []) {
            return [];
        }

        $touched = self::personRevisions(Page::query()->where('site_id', $site->getKey())->whereIn('uuid', $uuids)->select('uuid'))
            ->pluck('page_uuid')->map(strval(...))->all();

        return array_values(Page::query()->where('site_id', $site->getKey())->whereIn('uuid', $uuids)->get()
            ->filter(fn (Page $page): bool => self::isPrivateDraft($page) && ! in_array((string) $page->uuid, $touched, true))
            ->all());
    }

    /**
     * Whether setup's Publish still has something to do: it created pages, kept the spec it built,
     * has not published or been settled, and the placeholder home page or at least one created page
     * is untouched. It ends only through setup's Publish or once nothing untouched remains; nothing
     * the person does in the editor writes to it. A failure to read counts as nothing left to publish.
     */
    public static function awaitsPublication(Site $site): bool
    {
        if (! self::setupWaiting($site) || ! is_array(data_get($site->meta, 'guided_setup.spec'))) {
            return false;
        }

        try {
            return self::placeholderHome($site) instanceof Page || self::untouchedSetupPages($site) !== [];
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /**
     * Setup created pages before it kept the spec it built, so its Publish cannot run. Such a site
     * is settled once, with a message, instead of offering a Publish that would only be refused.
     */
    public static function isStranded(Site $site): bool
    {
        return self::setupWaiting($site) && ! is_array(data_get($site->meta, 'guided_setup.spec'));
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

        return $site instanceof Site && self::awaitsPublication($site) ? $site : null;
    }

    /** The person's site whose setup can no longer be published, so it can be settled with a message. */
    public function strandedSetup(Authenticatable $actor): ?Site
    {
        $site = SiteAccess::forActor($actor)->query(Site::class)->excludingPreview()->orderBy('id')->first();

        return $site instanceof Site && self::isStranded($site) ? $site : null;
    }

    /**
     * @param  Builder<Page>  $pageUuids
     * @return Builder<PageRevision>
     */
    private static function personRevisions(Builder $pageUuids): Builder
    {
        return PageRevision::query()
            ->whereIn('page_uuid', $pageUuids)
            ->whereNotNull('actor_id')
            ->where('is_rollback', false)
            ->where(fn (Builder $query): Builder => $query->whereNull('source')->orWhere('source', PageVersionSource::Edit->value));
    }

    /** A site is live once its go-live is recorded or a home page other than the install's placeholder is public now. */
    private static function isLive(Site $site): bool
    {
        if (resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey())) {
            return true;
        }

        return Page::query()->where('site_id', $site->getKey())->homePage()->published()->get()
            ->contains(fn (Page $home): bool => ! self::isPlaceholderHome($site, $home));
    }

    private static function setupWaiting(Site $site): bool
    {
        return filled(data_get($site->meta, 'guided_setup.completed_at'))
            && blank(data_get($site->meta, 'guided_setup.published_at'))
            && blank(data_get($site->meta, 'guided_setup.settled_at'));
    }

    private function build(Authenticatable $actor): GuidedSetupStateData
    {
        $site = SiteAccess::forActor($actor)->query(Site::class)->excludingPreview()->orderBy('id')->first();

        return $site instanceof Site && self::isOwedTo($site) ? GuidedSetupStateData::forSite($site) : GuidedSetupStateData::notNeeded();
    }
}
