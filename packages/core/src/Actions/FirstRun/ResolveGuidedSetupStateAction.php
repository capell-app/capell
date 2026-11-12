<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Data\FirstRun\GuidedSetupStateData;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
     * The public home page a first install leaves in place until the person publishes the site:
     * the very page the install recorded, with the copy it recorded. Its being public is not a sign
     * that anyone has published the site; once anything changes its copy, it counts like any other
     * public home page.
     */
    public static function isPlaceholderHome(Site $site, Page $home): bool
    {
        $mark = data_get($site->meta, self::FRESH_INSTALL_META_KEY);

        if (! is_array($mark) || ! is_string($mark['home_uuid'] ?? null) || ! is_string($mark['home_hash'] ?? null)) {
            return false;
        }

        $hash = hash_equals($mark['home_uuid'], (string) $home->uuid) ? self::homeHash($home) : null;

        return $hash !== null && hash_equals($mark['home_hash'], $hash);
    }

    /**
     * A hash of the home page as stored: its name, blueprint and content structure, and the raw
     * title, content and meta of every translation, deleted ones included. It reads the stored
     * columns rather than cast values, so content of any shape (HTML or blocks) hashes the same
     * way, and a change by anyone, person or seeder, is seen. Null when it cannot be worked out,
     * which every caller takes as "not the placeholder", so a failure never offers setup.
     */
    public static function homeHash(Page $home): ?string
    {
        try {
            $copy = DB::table((new Translation)->getTable())
                ->where('translatable_type', $home->getMorphClass())
                ->where('translatable_id', $home->getKey())
                ->orderBy('language_id')
                ->orderBy('id')
                ->get(['language_id', 'title', 'content', 'meta', 'deleted_at'])
                ->map(fn (object $row): array => [$row->language_id, $row->title, $row->content, $row->meta, $row->deleted_at])
                ->all();

            return hash('sha256', json_encode([
                $home->getRawOriginal('name'),
                $home->getRawOriginal('blueprint_id'),
                $home->getRawOriginal('content_structure_override'),
                $home->getRawOriginal('deleted_at'),
                $copy,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
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

        if (! $site instanceof Site
            || blank(data_get($site->meta, 'guided_setup.completed_at'))
            || filled(data_get($site->meta, 'guided_setup.published_at'))) {
            return null;
        }

        // Taken public from the editor since: the wait ends here rather than offering to publish over it.
        return SettleGuidedSetupPublicationAction::run($site) ? $site : null;
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

    private function build(Authenticatable $actor): GuidedSetupStateData
    {
        $site = SiteAccess::forActor($actor)->query(Site::class)->excludingPreview()->orderBy('id')->first();

        return $site instanceof Site && self::isOwedTo($site) ? GuidedSetupStateData::forSite($site) : GuidedSetupStateData::notNeeded();
    }
}
