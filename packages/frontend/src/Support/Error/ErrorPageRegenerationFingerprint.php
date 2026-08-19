<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\Cache;

/**
 * Cheap signature of the values a site's static error pages are rendered from.
 *
 * Deliberately built from rendered values only — never from `updated_at` — so a
 * row that is merely touched cannot cost a full re-render, while a genuine copy,
 * domain, theme, logo or layout change always does.
 *
 * Regeneration renders every supported status for every domain, so it must not
 * run again while its inputs are unchanged. A public 404 flood only ever writes
 * unrelated rows, and the model that dispatched the regeneration is often not an
 * input to the rendered output at all. Comparing this signature against the one
 * stored when the current artefacts were written turns "regenerate on every
 * change event" into "regenerate when the output would actually differ", using a
 * handful of indexed lookups instead of a full render.
 *
 * The signature covers database inputs only. Artefacts removed from disk (a
 * fresh deploy, a cleared storage directory) are caught separately by
 * `hasArtefacts()`, so a matching signature can never mean "skip" while nothing
 * is published.
 */
final readonly class ErrorPageRegenerationFingerprint
{
    private const string CACHE_PREFIX = 'capell.error-pages.fingerprint:';

    public function __construct(private ErrorPageManifestStore $manifestStore) {}

    public function current(Site $site): string
    {
        $siteId = (int) $site->getKey();

        $parts = [
            'site' => [
                $siteId,
                $site->name,
                $site->theme_id,
                $site->language_id,
                $site->isEnabled(),
            ],
            'domains' => SiteDomain::query()
                ->where('site_id', $siteId)
                ->orderBy('id')
                ->get(['id', 'domain', 'scheme', 'path', 'language_id', 'status'])
                ->map(fn (SiteDomain $siteDomain): array => [
                    (int) $siteDomain->getKey(),
                    $siteDomain->domain,
                    $siteDomain->scheme,
                    $siteDomain->path,
                    $siteDomain->language_id,
                    $siteDomain->status,
                ])
                ->all(),
            'site_translations' => $this->translationStamps(resolve(Site::class)->getMorphClass(), [$siteId]),
            'logo' => Media::query()
                ->where('model_type', resolve(Site::class)->getMorphClass())
                ->where('model_id', $siteId)
                ->whereIn('collection_name', [
                    MediaCollectionEnum::Logo->value,
                    MediaCollectionEnum::LogoInverted->value,
                ])
                ->orderBy('id')
                ->get(['id', 'collection_name', 'file_name', 'disk', 'size'])
                ->map(fn (Media $media): array => [
                    (int) $media->getKey(),
                    $media->collection_name,
                    $media->file_name,
                    $media->disk,
                    $media->size,
                ])
                ->all(),
            'error_pages' => $this->errorPageStamps($siteId),
        ];

        return hash('sha256', (string) json_encode($parts));
    }

    public function stored(int $siteId): ?string
    {
        $value = Cache::get(self::CACHE_PREFIX . $siteId);

        return is_string($value) ? $value : null;
    }

    public function remember(int $siteId, string $fingerprint): void
    {
        Cache::forever(self::CACHE_PREFIX . $siteId, $fingerprint);
    }

    public function forget(int $siteId): void
    {
        Cache::forget(self::CACHE_PREFIX . $siteId);
    }

    /**
     * True when the site currently has published error-page manifest entries.
     * A matching fingerprint only justifies skipping regeneration when the
     * artefacts it describes still exist.
     */
    public function hasArtefacts(int $siteId): bool
    {
        return $this->manifestEntryCount($siteId) > 0;
    }

    /** @return array<string, mixed> */
    private function errorPageStamps(int $siteId): array
    {
        $pages = Page::query()
            ->where('site_id', $siteId)
            ->whereHas('blueprint', fn ($query) => $query->where('key', 'error'))
            ->orderBy('id')
            ->get(['id', 'name', 'layout_id', 'blueprint_id', 'visible_from', 'visible_until']);

        /** @var array<int, int> $pageIds */
        $pageIds = $pages->map(fn (Page $page): int => (int) $page->getKey())->all();

        return [
            'pages' => $pages->map(fn (Page $page): array => [
                (int) $page->getKey(),
                $page->name,
                $page->layout_id,
                $page->blueprint_id,
                $page->visible_from?->toIso8601String(),
                $page->visible_until?->toIso8601String(),
            ])->all(),
            'translations' => $this->translationStamps(resolve(Page::class)->getMorphClass(), $pageIds),
        ];
    }

    /**
     * @param  array<int, int>  $translatableIds
     * @return array<int, array<int, mixed>>
     */
    private function translationStamps(string $translatableType, array $translatableIds): array
    {
        if ($translatableIds === []) {
            return [];
        }

        return Translation::query()
            ->where('translatable_type', $translatableType)
            ->whereIn('translatable_id', $translatableIds)
            ->orderBy('id')
            ->get(['id', 'language_id', 'translatable_id', 'title', 'content', 'meta'])
            ->map(fn (Translation $translation): array => [
                (int) $translation->getKey(),
                $translation->language_id,
                $translation->translatable_id,
                $translation->title,
                is_string($translation->content) ? hash('xxh128', $translation->content) : null,
                hash('xxh128', (string) json_encode($translation->meta)),
            ])
            ->all();
    }

    private function manifestEntryCount(int $siteId): int
    {
        $sites = $this->manifestStore->read()['sites'] ?? [];

        if (! is_array($sites)) {
            return 0;
        }

        $entries = $sites[(string) $siteId]['entries'] ?? [];

        return is_array($entries) ? count($entries) : 0;
    }
}
