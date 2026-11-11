<?php

declare(strict_types=1);

namespace Capell\Core\Support\FirstRun;

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Settings\FirstRunSettings;
use Carbon\CarbonImmutable;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Which sites have gone live. A site goes live the first time one of its home
 * pages is public; the first time is kept, so unpublishing and republishing
 * never resets it. Reads cost no query beyond the cached settings row.
 */
final class SitePublicationMilestones
{
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
        $settings = $this->settings();

        return $settings instanceof FirstRunSettings
            ? array_values(array_map(intval(...), array_keys($settings->published_sites)))
            : [];
    }

    public function hasPublished(int $siteId): bool
    {
        return in_array($siteId, $this->publishedSiteIds(), true);
    }

    public function publishedAt(int $siteId): ?CarbonImmutable
    {
        $value = $this->settings()?->published_sites[$siteId] ?? null;

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /** @return bool whether this call recorded the milestone */
    public function record(int $siteId, CarbonImmutable $at): bool
    {
        $settings = $this->settings();
        if (! $settings instanceof FirstRunSettings || $siteId <= 0 || array_key_exists($siteId, $settings->published_sites)) {
            return false;
        }

        // Assign by key: spreading an integer-keyed array would renumber the site ids.
        $sites = $settings->published_sites;
        $sites[$siteId] = $at->toIso8601String();
        $settings->published_sites = $sites;
        $settings->save();

        return true;
    }

    private function settings(): ?FirstRunSettings
    {
        try {
            return resolve(FirstRunSettings::class);
        } catch (MissingSettings) {
            // The settings migration has not run yet; nothing has been recorded.
            return null;
        }
    }
}
