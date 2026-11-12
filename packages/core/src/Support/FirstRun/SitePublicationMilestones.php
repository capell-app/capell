<?php

declare(strict_types=1);

namespace Capell\Core\Support\FirstRun;

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which sites have gone live. A site goes live the first time one of its home
 * pages is public; the first time is kept in the site's own meta
 * (`first_run.published_at`), so unpublishing and republishing never resets it
 * and deleting a site takes its milestone with it.
 */
final class SitePublicationMilestones
{
    public const string META_KEY = 'first_run.published_at';

    /** A home page counts as public once it is published and not hidden from search engines. */
    public static function isPublicHome(Page $home, ?CarbonImmutable $now = null): bool
    {
        if ($home->publishVisibilityState($now) !== PublishVisibilityStateEnum::published) {
            return false;
        }

        $robots = data_get($home->meta, 'robots');

        return ! filter_var(data_get($home->meta, 'noindex'), FILTER_VALIDATE_BOOL)
            && (! is_string($robots) || ! str_contains(strtolower($robots), 'noindex'));
    }

    /** @return list<int> */
    public function publishedSiteIds(): array
    {
        $ids = [];
        foreach (Site::query()->whereNotNull('meta->first_run->published_at')->orderBy('id')->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    public function hasPublished(int $siteId): bool
    {
        return in_array($siteId, $this->publishedSiteIds(), true);
    }

    public function publishedAt(int $siteId): ?CarbonImmutable
    {
        $value = data_get(Site::query()->whereKey($siteId)->value('meta'), self::META_KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /** @return bool whether this call recorded the milestone */
    public function record(int $siteId, CarbonImmutable $at): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        // The row is locked while it is read and written, so two requests that
        // both see a home page go public cannot overwrite each other's meta.
        $recorded = DB::transaction(function () use ($siteId, $at): bool {
            $site = Site::query()->lockForUpdate()->find($siteId);
            if (! $site instanceof Site || data_get($site->meta, self::META_KEY) !== null) {
                return false;
            }

            $meta = is_array($site->meta) ? $site->meta : [];
            data_set($meta, self::META_KEY, $at->toIso8601String());
            $site->timestamps = false;
            $site->meta = $meta;
            $site->saveQuietly();

            return true;
        });

        if ($recorded) {
            resolve(GuidedSetupStateMemo::class)->flush();
        }

        return $recorded;
    }
}
