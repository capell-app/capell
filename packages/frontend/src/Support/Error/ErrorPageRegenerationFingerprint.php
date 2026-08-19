<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Error;

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;

/**
 * Cheap signature of the values a site's static error pages are rendered from.
 *
 * Regeneration renders every supported status for every domain, so it must not
 * run again while its inputs are unchanged. A public 404 flood only ever writes
 * unrelated rows, and the model that dispatched the regeneration is often not an
 * input to the rendered output at all. Comparing this signature against the one
 * stored when the current artefacts were written turns "regenerate on every
 * change event" into "regenerate when the output would actually differ", using a
 * handful of indexed lookups instead of a full render.
 *
 * Deliberately built from rendered values only — never from `updated_at` — so a
 * row that is merely touched cannot cost a full re-render, while a genuine copy,
 * domain, theme, logo or layout change always does.
 *
 * Every read goes through the query builder rather than Eloquent: these rows are
 * only ever hashed, so casts, accessors and relation-loading are pure cost, and
 * one of them (`DynamicContentCast` resolving `translatable`) turns a partially
 * selected translation row into a broken query. Raw column values also hash more
 * predictably than cast objects.
 *
 * The signature is stored in the error-page manifest, beside the artefacts it
 * describes, so the two share one lifetime: a manifest that is rewritten,
 * deleted or restored carries its fingerprint with it, and no cache driver,
 * cache flush or process boundary can leave the gate silently unable to skip.
 */
final readonly class ErrorPageRegenerationFingerprint
{
    public function __construct(private ErrorPageManifestStore $manifestStore) {}

    public function current(Site $site): string
    {
        $siteId = (int) $site->getKey();

        return hash('sha256', (string) json_encode([
            'site' => Site::query()
                ->whereKey($siteId)
                ->toBase()
                ->get(['id', 'name', 'theme_id', 'language_id', 'status'])
                ->map($this->row(...))
                ->all(),
            'domains' => SiteDomain::query()
                ->where('site_id', $siteId)
                ->orderBy('id')
                ->toBase()
                ->get(['id', 'domain', 'scheme', 'path', 'language_id', 'status'])
                ->map($this->row(...))
                ->all(),
            'site_translations' => $this->translationValues(resolve(Site::class)->getMorphClass(), [$siteId]),
            'logo' => Media::query()
                ->where('model_type', resolve(Site::class)->getMorphClass())
                ->where('model_id', $siteId)
                ->whereIn('collection_name', [
                    MediaCollectionEnum::Logo->value,
                    MediaCollectionEnum::LogoInverted->value,
                ])
                ->orderBy('id')
                ->toBase()
                ->get(['id', 'collection_name', 'file_name', 'disk', 'size'])
                ->map($this->row(...))
                ->all(),
            'error_pages' => $this->errorPageValues($siteId),
        ]));
    }

    public function stored(int $siteId): ?string
    {
        return $this->manifestStore->fingerprintFor($siteId);
    }

    public function remember(int $siteId, string $fingerprint): void
    {
        $this->manifestStore->rememberFingerprint($siteId, $fingerprint);
    }

    public function forget(int $siteId): void
    {
        $this->manifestStore->forgetFingerprint($siteId);
    }

    /**
     * True when the site currently has published error-page manifest entries.
     * A matching fingerprint only justifies skipping regeneration when the
     * artefacts it describes still exist.
     */
    public function hasArtefacts(int $siteId): bool
    {
        return $this->manifestStore->entryCountFor($siteId) > 0;
    }

    /** @return array<string, mixed> */
    private function errorPageValues(int $siteId): array
    {
        $pages = Page::query()
            ->where('site_id', $siteId)
            ->whereHas('blueprint', fn ($query) => $query->where('key', 'error'))
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'name', 'layout_id', 'blueprint_id', 'visible_from', 'visible_until']);

        /** @var array<int, int> $pageIds */
        $pageIds = $pages->pluck('id')->map(intval(...))->all();

        return [
            'pages' => $pages->map($this->row(...))->all(),
            'translations' => $this->translationValues(resolve(Page::class)->getMorphClass(), $pageIds),
        ];
    }

    /**
     * @param  array<int, int>  $translatableIds
     * @return array<int, array<string, mixed>>
     */
    private function translationValues(string $translatableType, array $translatableIds): array
    {
        if ($translatableIds === []) {
            return [];
        }

        return Translation::query()
            ->where('translatable_type', $translatableType)
            ->whereIn('translatable_id', $translatableIds)
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'language_id', 'translatable_id', 'title', 'content', 'meta'])
            ->map(function (object $translation): array {
                $values = $this->row($translation);

                // Content and meta are the only large columns here; hashing
                // them keeps the signature small without losing sensitivity.
                $values['content'] = hash('xxh128', (string) ($values['content'] ?? ''));
                $values['meta'] = hash('xxh128', (string) ($values['meta'] ?? ''));

                return $values;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        $values = (array) $row;

        ksort($values);

        return array_map(
            fn (mixed $value): mixed => is_scalar($value) || $value === null ? $value : (string) json_encode($value),
            $values,
        );
    }
}
