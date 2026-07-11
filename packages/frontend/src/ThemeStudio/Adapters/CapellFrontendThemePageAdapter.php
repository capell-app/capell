<?php

declare(strict_types=1);

namespace Capell\Frontend\ThemeStudio\Adapters;

use Capell\Core\Actions\Content\ExtractTextContentAction;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Security\PublicUrlSanitizer;
use Capell\Core\ThemeStudio\Contracts\ThemePageAdapter;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Capell\Core\ThemeStudio\Data\ContentListingSectionData;
use Capell\Core\ThemeStudio\Data\CtaSectionData;
use Capell\Core\ThemeStudio\Data\FeatureSectionData;
use Capell\Core\ThemeStudio\Data\FooterData;
use Capell\Core\ThemeStudio\Data\GenericSectionData;
use Capell\Core\ThemeStudio\Data\HeroSectionData;
use Capell\Core\ThemeStudio\Data\NavigationData;
use Capell\Core\ThemeStudio\Data\ProofSectionData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Frontend\Facades\Frontend;
use Illuminate\Database\Eloquent\Model;

class CapellFrontendThemePageAdapter implements ThemePageAdapter
{
    public function currentPage(): ThemePageData
    {
        $page = Frontend::page();
        $translation = $page?->translation;
        $brand = resolve(ThemeRuntimeSettings::class)->brandProfile();
        $title = $this->titleFrom($translation, $page?->name ?? 'Untitled page');
        $renderData = $this->renderData($page, $translation);
        $sections = $this->sectionsFrom($renderData, $title, $translation);
        $navigation = $this->navigationFrom($renderData) ?? $this->defaultNavigation();

        return new ThemePageData(
            title: $title,
            brand: $brand,
            sections: $sections,
            navigation: $navigation,
            footer: $this->footerFrom($renderData, $navigation),
        );
    }

    /**
     * @param  array<string, mixed>  $renderData
     * @return array<int, ThemeSection>
     */
    private function sectionsFrom(array $renderData, string $title, ?Translation $translation): array
    {
        $ordered = $this->orderedSectionsFrom($renderData, $title);

        if ($ordered !== []) {
            return $ordered;
        }

        $sections = [];
        $hero = data_get($renderData, 'hero');

        if (is_array($hero)) {
            $sections[] = HeroSectionData::from([
                'heading' => data_get($hero, 'heading', $title),
                'eyebrow' => data_get($hero, 'eyebrow'),
                'summary' => data_get($hero, 'summary', $this->summaryFrom($translation?->content)),
                'actions' => $this->publicLinksFrom(data_get($hero, 'actions', []), includeStyle: true),
                'mediaUrl' => PublicUrlSanitizer::sanitize(data_get($hero, 'mediaUrl', data_get($hero, 'media_url'))),
                'mediaAlt' => data_get($hero, 'mediaAlt'),
            ]);
        }

        $features = $this->featuresFrom($renderData);

        if ($features instanceof FeatureSectionData) {
            $sections[] = $features;
        }

        $items = $this->contentListingFrom(
            renderData: $renderData,
            key: 'items',
            defaultHeading: 'Browse entries',
        );

        if ($items instanceof ContentListingSectionData) {
            $sections[] = $items;
        }

        $gallery = $this->contentListingFrom(
            renderData: $renderData,
            key: 'gallery',
            defaultHeading: 'Gallery',
            variant: 'gallery',
        );

        if ($gallery instanceof ContentListingSectionData) {
            $sections[] = $gallery;
        }

        $spotlight = $this->contentListingFrom(
            renderData: $renderData,
            key: 'spotlight',
            defaultHeading: 'Spotlight',
            variant: 'spotlight',
        );

        if ($spotlight instanceof ContentListingSectionData) {
            $sections[] = $spotlight;
        }

        $proof = $this->proofFrom($renderData);

        if ($proof instanceof ProofSectionData) {
            $sections[] = $proof;
        }

        $cta = data_get($renderData, 'cta');

        if (is_array($cta)) {
            $sections[] = new CtaSectionData(
                heading: (string) data_get($cta, 'heading', ''),
                summary: $this->stringOrNull(data_get($cta, 'summary')),
                actions: $this->publicLinksFrom(data_get($cta, 'actions', []), includeStyle: true),
            );
        }

        return $sections !== [] ? $sections : [$this->fallbackHero($title, $translation)];
    }

    /**
     * Build sections from an explicit ordered list (`render_data['sections']`).
     *
     * Each entry is `{type, ...payload}`: the five known types map to their typed
     * core sections (reusing this class's URL/link sanitization); any other type
     * becomes a {@see GenericSectionData} carrying the entry payload verbatim, so
     * a theme's signature renderer (resolved by `type`) receives it directly.
     * Returns `[]` when no usable ordered list is present, leaving the implicit
     * key-based path in {@see self::sectionsFrom()} fully intact.
     *
     * @param  array<string, mixed>  $renderData
     * @return array<int, ThemeSection>
     */
    private function orderedSectionsFrom(array $renderData, string $title): array
    {
        $ordered = data_get($renderData, 'sections');

        if (! is_array($ordered) || ! array_is_list($ordered) || $ordered === []) {
            return [];
        }

        $sections = [];

        foreach ($ordered as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $section = $this->sectionFromEntry($this->stringKeyedArray($entry), $title);

            if ($section instanceof ThemeSection) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function sectionFromEntry(array $entry, string $title): ?ThemeSection
    {
        $type = $this->stringOrNull(data_get($entry, 'type'));

        if ($type === null) {
            return null;
        }

        return match ($type) {
            'hero' => HeroSectionData::from([
                'heading' => data_get($entry, 'heading', $title),
                'eyebrow' => data_get($entry, 'eyebrow'),
                'summary' => $this->stringOrNull(data_get($entry, 'summary')),
                'actions' => $this->publicLinksFrom(data_get($entry, 'actions', []), includeStyle: true),
                'mediaUrl' => PublicUrlSanitizer::sanitize(data_get($entry, 'mediaUrl', data_get($entry, 'media_url'))),
                'mediaAlt' => data_get($entry, 'mediaAlt'),
            ]),
            'features' => FeatureSectionData::from([
                'heading' => data_get($entry, 'heading', 'Featured modules'),
                'summary' => data_get($entry, 'summary'),
                'features' => collect($this->entryItems($entry, 'features'))
                    ->map(fn (array $feature): array => [
                        'title' => (string) data_get($feature, 'title', data_get($feature, 'name', 'Feature')),
                        'description' => (string) data_get($feature, 'description', data_get($feature, 'summary', '')),
                        'icon' => data_get($feature, 'icon'),
                        'image' => data_get($feature, 'image', data_get($feature, 'imageUrl')),
                        'type' => data_get($feature, 'type'),
                    ])
                    ->values()
                    ->all(),
            ]),
            'content-listing' => ContentListingSectionData::from([
                'heading' => data_get($entry, 'heading', 'Browse entries'),
                'summary' => data_get($entry, 'summary'),
                'items' => $this->entryItems($entry, 'items'),
                'variant' => data_get($entry, 'variant'),
            ]),
            'proof' => ProofSectionData::from([
                'heading' => data_get($entry, 'heading', 'Proof points'),
                'summary' => data_get($entry, 'summary'),
                'items' => $this->entryItems($entry, 'items'),
            ]),
            'cta' => new CtaSectionData(
                heading: (string) data_get($entry, 'heading', ''),
                summary: $this->stringOrNull(data_get($entry, 'summary')),
                actions: $this->publicLinksFrom(data_get($entry, 'actions', []), includeStyle: true),
            ),
            default => $this->genericSectionFromEntry($type, $entry),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function entryItems(array $entry, string $key): array
    {
        $items = data_get($entry, $key);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(
            $this->stringKeyedArray(...),
            array_filter($items, is_array(...)),
        ));
    }

    /**
     * Keep only string keys so JSON-decoded render data narrows to a section payload.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        return array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function genericSectionFromEntry(string $type, array $entry): GenericSectionData
    {
        $payload = $entry;
        unset($payload['type'], $payload['fallback']);

        $fallback = $this->stringOrNull(data_get($entry, 'fallback'));

        return new GenericSectionData(
            type: $type,
            data: $payload,
            fallback: $fallback ?? 'content-listing',
        );
    }

    /**
     * @param  array<string, mixed>  $renderData
     */
    private function featuresFrom(array $renderData): ?FeatureSectionData
    {
        $features = data_get($renderData, 'features');

        if (! is_array($features) || $features === []) {
            return null;
        }

        return FeatureSectionData::from([
            'heading' => data_get($renderData, 'features_heading', 'Featured modules'),
            'summary' => data_get($renderData, 'features_summary', data_get($renderData, 'summary')),
            'features' => collect($features)
                ->filter(fn (mixed $feature): bool => is_array($feature))
                ->map(fn (array $feature): array => [
                    'title' => (string) data_get($feature, 'title', data_get($feature, 'name', 'Feature')),
                    'description' => (string) data_get($feature, 'description', data_get($feature, 'summary', '')),
                    'icon' => data_get($feature, 'icon'),
                    'image' => data_get($feature, 'image', data_get($feature, 'imageUrl')),
                    'type' => data_get($feature, 'type'),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $renderData
     */
    private function contentListingFrom(array $renderData, string $key, string $defaultHeading, ?string $variant = null): ?ContentListingSectionData
    {
        $source = data_get($renderData, $key);

        if (! is_array($source)) {
            return null;
        }

        $sourceIsList = array_is_list($source);
        $items = $sourceIsList ? $source : data_get($source, 'items', []);

        if (! is_array($items) || $items === []) {
            return null;
        }

        return ContentListingSectionData::from([
            'heading' => $sourceIsList
                ? data_get($renderData, $key . '_heading', data_get($renderData, 'heading', $defaultHeading))
                : data_get($source, 'heading', $defaultHeading),
            'summary' => $sourceIsList
                ? data_get($renderData, $key . '_summary', data_get($renderData, 'summary'))
                : data_get($source, 'summary'),
            'items' => $items,
            'variant' => $sourceIsList ? data_get($renderData, $key . '_variant', $variant) : data_get($source, 'variant', $variant),
        ]);
    }

    /**
     * @param  array<string, mixed>  $renderData
     */
    private function proofFrom(array $renderData): ?ProofSectionData
    {
        $proof = data_get($renderData, 'proof');

        if (! is_array($proof)) {
            return null;
        }

        $items = array_is_list($proof) ? $proof : data_get($proof, 'items', []);

        if (! is_array($items) || $items === []) {
            return null;
        }

        return ProofSectionData::from([
            'heading' => array_is_list($proof) ? 'Proof points' : data_get($proof, 'heading', 'Proof points'),
            'summary' => array_is_list($proof) ? null : data_get($proof, 'summary'),
            'items' => $items,
        ]);
    }

    private function fallbackHero(string $title, ?Translation $translation): HeroSectionData
    {
        return HeroSectionData::from([
            'heading' => $title,
            'summary' => $this->summaryFrom($translation?->content),
        ]);
    }

    private function defaultNavigation(): NavigationData
    {
        $site = Frontend::site();

        return NavigationData::from([
            'brandName' => $site?->title ?? $site?->name ?? __('capell-frontend::generic.site'),
            'items' => [
                ['label' => __('capell-frontend::generic.home'), 'url' => '/'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $renderData
     */
    private function navigationFrom(array $renderData): ?NavigationData
    {
        $navigation = data_get($renderData, 'navigation');

        if (! is_array($navigation)) {
            return null;
        }

        if (array_is_list($navigation)) {
            return new NavigationData(
                brandName: $this->defaultNavigation()->brandName,
                items: $this->publicLinksFrom($navigation),
            );
        }

        return new NavigationData(
            brandName: $this->stringOrNull(data_get($navigation, 'brandName', data_get($navigation, 'brand_name')))
                ?? $this->defaultNavigation()->brandName,
            items: $this->publicLinksFrom(data_get($navigation, 'items', [])),
            ctaLabel: $this->stringOrNull(data_get($navigation, 'ctaLabel', data_get($navigation, 'cta_label'))),
            ctaUrl: PublicUrlSanitizer::sanitize(data_get($navigation, 'ctaUrl', data_get($navigation, 'cta_url'))),
        );
    }

    /**
     * @param  array<string, mixed>  $renderData
     */
    private function footerFrom(array $renderData, NavigationData $navigation): FooterData
    {
        $footer = data_get($renderData, 'footer');

        if (is_array($footer) && array_is_list($footer)) {
            return new FooterData(
                brandName: $navigation->brandName,
                columns: $this->footerColumnsFrom($footer),
            );
        }

        if (is_array($footer)) {
            return new FooterData(
                brandName: $this->stringOrNull(data_get($footer, 'brandName', data_get($footer, 'brand_name')))
                    ?? $navigation->brandName,
                summary: $this->stringOrNull(data_get($footer, 'summary')),
                columns: $this->footerColumnsFrom(data_get($footer, 'columns', [])),
            );
        }

        return $this->defaultFooter($navigation);
    }

    private function defaultFooter(NavigationData $navigation): FooterData
    {
        return FooterData::from([
            'brandName' => $navigation->brandName,
        ]);
    }

    private function titleFrom(?Translation $translation, string $fallback): string
    {
        $title = $translation?->title;

        return is_string($title) && $title !== '' ? $title : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderData(mixed $page, ?Translation $translation): array
    {
        $pageMeta = $page instanceof Model ? $page->getAttribute('meta') : null;

        foreach ([
            data_get($translation?->meta, 'theme.render_data'),
            data_get($translation?->meta, 'render_data'),
            data_get($pageMeta, 'theme.render_data'),
            data_get($pageMeta, 'render_data'),
            data_get($pageMeta, 'theme_demo.render_data'),
            data_get($translation?->meta, 'theme_demo'),
        ] as $renderData) {
            if (is_array($renderData)) {
                return $this->sanitizeRenderDataUrls($renderData);
            }

        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $renderData
     * @return array<string, mixed>
     */
    private function sanitizeRenderDataUrls(array $renderData): array
    {
        foreach ($renderData as $key => $value) {
            if (is_array($value)) {
                $renderData[$key] = $this->sanitizeRenderDataUrls($value);

                continue;
            }

            if (is_string($key) && $this->isPublicUrlKey($key)) {
                $renderData[$key] = PublicUrlSanitizer::sanitize($value);
            }
        }

        return $renderData;
    }

    private function isPublicUrlKey(string $key): bool
    {
        return in_array($key, [
            'url',
            'ctaUrl',
            'cta_url',
            'fallbackUrl',
            'fallback_url',
            'mediaUrl',
            'media_url',
            'image',
            'imageUrl',
            'image_url',
        ], true);
    }

    /**
     * @return list<array{label: string, url: string, style?: string}>
     */
    private function publicLinksFrom(mixed $links, bool $includeStyle = false): array
    {
        if (! is_array($links)) {
            return [];
        }

        $publicLinks = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = $this->stringOrNull(data_get($link, 'label'));
            $url = PublicUrlSanitizer::sanitize(data_get($link, 'url'));
            if ($label === null) {
                continue;
            }

            if ($url === null) {
                continue;
            }

            $publicLink = [
                'label' => $label,
                'url' => $url,
            ];

            $style = $this->stringOrNull(data_get($link, 'style'));

            if ($includeStyle && $style !== null) {
                $publicLink['style'] = $style;
            }

            $publicLinks[] = $publicLink;
        }

        return $publicLinks;
    }

    /**
     * @return list<array{heading: string, links: list<array{label: string, url: string}>}>
     */
    private function footerColumnsFrom(mixed $columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        $footerColumns = [];

        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }

            $heading = $this->stringOrNull(data_get($column, 'heading'));
            $links = $this->publicLinksFrom(data_get($column, 'links', []));
            if ($heading === null) {
                continue;
            }

            if ($links === []) {
                continue;
            }

            $footerColumns[] = [
                'heading' => $heading,
                'links' => array_map(
                    static fn (array $link): array => [
                        'label' => $link['label'],
                        'url' => $link['url'],
                    ],
                    $links,
                ),
            ];
        }

        return $footerColumns;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function summaryFrom(mixed $content): ?string
    {
        $summary = ExtractTextContentAction::run($content, 40);

        return $summary === '' ? null : $summary;
    }
}
