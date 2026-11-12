<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\GuidedSetupStateMemo;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Ends the wait for guided setup's Publish once the person has taken the site public another way:
 * a page setup created has left private draft (published, scheduled or unpublished from the
 * editor), or the placeholder home page has been edited, replaced or removed. The go-live is
 * written to the site, so the Publish button and the dashboard notice go and nothing setup
 * created is republished over what the person did since.
 */
final class SettleGuidedSetupPublicationAction
{
    use AsObject;

    /** Whether the page is one guided setup created and is waiting to publish. */
    public static function createdBySetup(Page $page): bool
    {
        $site = $page->site_id === null ? null : Site::query()->find($page->site_id);

        return $site instanceof Site
            && self::waiting($site)
            && in_array((string) $page->uuid, self::setupPageUuids($site), true);
    }

    /** @return list<string> */
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

    /** Whether the site is still waiting for guided setup's Publish. */
    public function handle(Site $site): bool
    {
        if (! self::waiting($site)) {
            return false;
        }

        if (! $this->takenPublic($site)) {
            return true;
        }

        $settled = DB::transaction(function () use ($site): bool {
            $locked = Site::query()->lockForUpdate()->whereKey($site->getKey())->first();

            if (! $locked instanceof Site || ! self::waiting($locked)) {
                return false;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            data_set($meta, 'guided_setup.published_at', now()->toIso8601String());
            $locked->forceFill(['meta' => $meta])->save();
            $site->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($settled && $this->homeIsPersonal($site)) {
            RecordSitePublishedAction::run((int) $site->getKey(), CarbonImmutable::now());
        }

        // The logo setup held back belongs on the site now it is public; a failure keeps it for another try.
        if ($settled) {
            DB::afterCommit(static fn (): mixed => rescue(static fn (): mixed => AttachGuidedSetupLogoAction::run($site->refresh())));
        }

        resolve(GuidedSetupStateMemo::class)->flush();

        return false;
    }

    private static function waiting(Site $site): bool
    {
        return filled(data_get($site->meta, 'guided_setup.completed_at'))
            && blank(data_get($site->meta, 'guided_setup.published_at'));
    }

    /** Any doubt counts as taken public, so nothing is ever republished over the person's work. */
    private function takenPublic(Site $site): bool
    {
        try {
            if (! $this->homeIsPlaceholder($site)) {
                return true;
            }

            return Page::query()->withTrashed()->where('site_id', $site->getKey())->whereIn('uuid', self::setupPageUuids($site))->get()
                ->contains(fn (Page $page): bool => ! $page->trashed() && ! self::isPrivateDraft($page));
        } catch (Throwable $throwable) {
            report($throwable);

            return true;
        }
    }

    private function homeIsPlaceholder(Site $site): bool
    {
        $home = $this->home($site);

        return $home instanceof Page && ResolveGuidedSetupStateAction::isPlaceholderHome($site, $home);
    }

    /** The install's home page, still public, now holding the person's own copy. */
    private function homeIsPersonal(Site $site): bool
    {
        try {
            $home = $this->home($site);

            return $home instanceof Page
                && Page::query()->whereKey($home->getKey())->published()->exists()
                && ! ResolveGuidedSetupStateAction::isPlaceholderHome($site, $home);
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    private function home(Site $site): ?Page
    {
        $uuid = data_get($site->meta, ResolveGuidedSetupStateAction::FRESH_INSTALL_META_KEY . '.home_uuid');

        return is_string($uuid) ? Page::query()->where('site_id', $site->getKey())->where('uuid', $uuid)->first() : null;
    }
}
