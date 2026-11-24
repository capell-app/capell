<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Enums\FontTypeEnum;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum;
use Capell\Core\Enums\SiteAiDisclosure;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\FontMimeTypeResolverInterface;
use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\ColorData;
use Capell\Frontend\Data\FrontendMediaHintData;
use Capell\Frontend\Data\PublicHeadData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookScenario;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\Render\HeadMetadataCollector;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\Security\HeadContentSanitizer;
use Capell\Frontend\Support\View\PublicModelMeta;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Stringable;
use Throwable;

final class BuildPublicHeadDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Factory $views) {}

    public function handle(mixed $title = '', bool $livewireEnabled = true, ?FrontendRuntimeManifestData $runtimeManifest = null, ?FrontendResourcePlanData $resourcePlan = null): PublicHeadData
    {
        $theme = Frontend::theme();
        $site = Frontend::site();
        $language = Frontend::language();
        $layout = Frontend::layout();
        $page = Frontend::page();
        $siteMeta = $site?->meta ?? [];
        $pageMeta = $page?->meta ?? [];
        $pageTranslation = $page instanceof Pageable && method_exists($page, 'relationLoaded') && $page->relationLoaded('translation') ? $page->translation : null;
        $siteTranslation = $site instanceof Site && method_exists($site, 'relationLoaded') && $site->relationLoaded('translation') ? $site->translation : null;
        $siteDomain = $site instanceof Site && method_exists($site, 'relationLoaded') && $site->relationLoaded('siteDomain') ? $site->siteDomain : null;
        $pageUrls = $page instanceof Pageable && method_exists($page, 'relationLoaded') && $page->relationLoaded('pageUrls') ? $page->pageUrls : collect();
        $stringValue = static fn (mixed $value): ?string => match (true) {
            is_string($value) => $value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => null,
        };
        $safeStringAttribute = static function (mixed $model, string $attribute) use ($stringValue): ?string {
            if (! is_object($model)) {
                return null;
            }

            try {
                return $stringValue(data_get($model, $attribute));
            } catch (Throwable) {
                return null;
            }
        };
        $title = $stringValue($title) ?? '';

        if ($title === '') {
            $translationMetaTitle = $stringValue(data_get($pageTranslation?->meta ?? [], 'title'))
                ?? $safeStringAttribute($pageTranslation, 'meta_title');

            if ($translationMetaTitle !== null && $translationMetaTitle !== '') {
                $title = $translationMetaTitle;
            } else {
                $pageTitle = $safeStringAttribute($pageTranslation, 'title');

                if ($pageTitle !== null && $pageTitle !== '') {
                    $title = $pageTitle;
                }

                $append = $stringValue(data_get($siteTranslation?->meta ?? [], 'title_after_text'))
                    ?? $safeStringAttribute($siteTranslation, 'title');

                if (! in_array($append, [null, '', $title], true)) {
                    $title .= config('capell-frontend.meta_title_seperator', ' ') . $append;
                }
            }
        }

        $description = $safeStringAttribute($pageTranslation, 'meta_description');

        $keywords = $safeStringAttribute($pageTranslation, 'meta_keywords');
        // Read from already-loaded site and page meta: no query, and only the disclosure word is output.
        $aiDisclosure = ($siteMeta[SiteAiDisclosure::META_KEY] ?? null) === SiteAiDisclosure::Marker->value
            ? AiDisclosure::tryFrom((string) ($pageMeta[AiDisclosure::PAGE_META_KEY] ?? ''))
            : null;
        $aiDisclosure = $aiDisclosure === AiDisclosure::None ? null : $aiDisclosure;

        $runtimeManifest ??= Frontend::getFrontendData('runtimeManifest')
        ?? FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
        $resourcePlan ??= Frontend::getFrontendData('resourcePlan');
        $renderedFrontendResources = Frontend::getFrontendData('renderedFrontendResources');

        if (! $renderedFrontendResources instanceof RenderedFrontendResourcesData && $resourcePlan instanceof FrontendResourcePlanData) {
            $renderedFrontendResources = resolve(FrontendResourcePlanRenderer::class)->render($resourcePlan, new FrontendResourceContextData(
                page: $page,
                site: $site,
                language: $language,
                layout: $layout,
                theme: $theme,
                runtime: $runtimeManifest,
            ));
        }

        $mediaHints = Frontend::getFrontendData('mediaHints') ?? [];

        $icon = data_get($siteMeta, 'icon');

        $favicon = data_get($siteMeta, 'favicon');

        $color = data_get($siteMeta, 'color');

        $colors = GetThemeColorsAction::run($theme);

        $fonts = PublicModelMeta::get($theme, 'fonts');

        $canonicalUrl = ResolvePageCanonicalUrlAction::run($page, $language);
        $robots = ResolvePageRobotsDirectivesAction::run($page, $language);
        $alternates = [];
        $defaultAlternateUrl = null;
        $translatedLanguageIds = $page instanceof Model && $page->relationLoaded('translations')
            ? $page->translations->pluck('language_id')->all()
            : [];
        foreach ($pageUrls instanceof EloquentCollection ? $pageUrls : [] as $url) {
            if (! $url instanceof PageUrl) {
                continue;
            }

            if ($url->type !== null) {
                continue;
            }

            if (! $url->status) {
                continue;
            }

            if (! in_array($url->language_id, $translatedLanguageIds, true)) {
                continue;
            }

            if (! $url->relationLoaded('siteDomain')) {
                continue;
            }

            if (! $url->siteDomain instanceof SiteDomain) {
                continue;
            }

            $alternateLanguage = $url->relationLoaded('language') ? $url->language : null;
            $alternateLanguage ??= $url->language_id === $language?->id ? $language : null;
            if (! $alternateLanguage instanceof Language) {
                continue;
            }

            $alternates[] = [
                'href' => $url->full_url,
                'hreflang' => Str::of($alternateLanguage->locale ?: $alternateLanguage->code)->lower()->replace('_', '-')->toString(),
            ];
            if ($site instanceof Site && $url->language_id === $site->language_id) {
                $defaultAlternateUrl ??= $url->full_url;
            }
        }

        if (count($alternates) < 2) {
            $alternates = [];
            $defaultAlternateUrl = null;
        }

        $pageVariables = GetPageVariablesAction::run($page, $site);
        $translationVariables = collect($pageVariables)
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof Stringable)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        $metaTitle = trans(strip_tags($title), $translationVariables);
        $socialTitle = trans(strip_tags($title), $translationVariables);

        if ($this->views->hasSection('meta_title')) {
            $metaTitle = $this->views->yieldContent('meta_title', $metaTitle);
            $socialTitle = strip_tags($metaTitle);
        }

        $collector = new HeadMetadataCollector;
        // Rendered first, so runtimes defined here (such as window.CapellPrivacy) exist before any other head script.
        $headOpenHook = $collector->collect(resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::HeadOpen, item: ['page' => $page, 'site' => $site, 'language' => $language]));
        $resourceHead = $collector->collect($renderedFrontendResources instanceof RenderedFrontendResourcesData ? $renderedFrontendResources->headHtml : '');
        $stylesHead = $collector->collect($this->views->yieldPushContent('styles'));
        $headHook = $collector->collect(resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::HeadClose, item: ['page' => $page, 'site' => $site, 'language' => $language], scenario: RenderHookScenario::SeoMeta->value));
        $siteHead = $collector->collect(HeadContentSanitizer::headSnippet(data_get($siteMeta, 'meta_tags')));
        $themeHead = $collector->collect(HeadContentSanitizer::headSnippet(PublicModelMeta::get($theme, 'meta_tags')));
        $pageHead = $collector->collect(HeadContentSanitizer::headSnippet(data_get($pageMeta, 'meta_tags')));
        $customHead = $collector->collect(RenderCustomHeadAction::run($title, $description, $keywords));
        $sectionHead = $collector->collect($this->views->yieldContent('meta'));
        $headMetadata = BuildHeadMetadataAction::run($socialTitle, $description, $canonicalUrl, $safeStringAttribute($siteTranslation, 'title'), $robots, Frontend::isError(), $collector->values(), ResolveHeadImageUrlAction::run($page, $site), $aiDisclosure?->digitalSourceType());

        $isGoogleFont = false;
        $fontUrls = [];
        foreach ($fonts ?: [] as $font) {
            if ($font['type'] !== 'url') {
                continue;
            }

            $fontUrl = $font['url'] ?? null;
            if (! $fontUrl) {
                continue;
            }

            if (in_array($fontUrl, $fontUrls, true)) {
                continue;
            }

            $fontUrls[] = $fontUrl;

            if (str_contains((string) $fontUrl, 'fonts.googleapis.com')) {
                $isGoogleFont = true;
            }
        }

        return new PublicHeadData(
            metaTitle: $metaTitle,
            metadata: $headMetadata->meta,
            jsonLd: json_encode(['@' . 'context' => 'https://schema.org', '@graph' => $headMetadata->graph], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
            baseUrl: $siteDomain?->full_url,
            fontUrls: $fontUrls,
            hasGoogleFont: $isGoogleFont,
            resourceHead: $resourceHead,
            mediaHints: array_map($this->mediaHint(...), $mediaHints),
            canonicalUrl: $canonicalUrl,
            iconUrl: $icon ? asset('storage/' . $icon) : null,
            faviconUrl: $favicon ? asset('storage/' . $favicon) : null,
            color: $stringValue($color),
            alternates: collect($alternates)->sortBy('hreflang')->values()->all(),
            defaultAlternateUrl: $defaultAlternateUrl,
            criticalCss: PublicModelMeta::get($theme, 'critical_asset') && PublicModelMeta::get($theme, 'assets_path')
                ? HeadContentSanitizer::css(wordwrap(GetCriticalCssContentAction::run(PublicModelMeta::get($theme, 'critical_asset'), PublicModelMeta::get($theme, 'assets_path'))))
                : null,
            fontFamily: HeadContentSanitizer::cssValue(PublicModelMeta::get($theme, 'font_family'), 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"'),
            headingFontFamily: HeadContentSanitizer::cssValue(PublicModelMeta::get($theme, 'font_heading_family'), 'var(--font-family)'),
            colors: collect($colors)->map(fn (ColorData $color): array => ['name' => $color->name, 'color' => $color->getColor()])->values()->all(),
            customCss: HeadContentSanitizer::css((string) ($theme->custom_css ?? '')),
            localFonts: $this->localFonts($fonts ?: []),
            stylesHead: $stylesHead,
            usesLivewire: $runtimeManifest?->usesLivewire ?? $livewireEnabled,
            description: $description,
            keywords: $keywords,
            aiDisclosure: $aiDisclosure?->value,
            headOpenHook: $headOpenHook,
            headHook: $headHook,
            siteHead: $siteHead,
            themeHead: $themeHead,
            pageHead: $pageHead,
            customHead: $customHead,
            sectionHead: $sectionHead,
        );
    }

    /** @return array{as: string, url: string, fetchPriority: string, imageSrcset: ?string, imageSizes: ?string, mimeType: ?string} */
    private function mediaHint(FrontendMediaHintData $hint): array
    {
        return [
            'as' => $hint->as,
            'url' => $hint->url,
            'fetchPriority' => $hint->fetchPriority,
            'imageSrcset' => $hint->imageSrcset,
            'imageSizes' => $hint->imageSizes,
            'mimeType' => $hint->mimeType,
        ];
    }

    /**
     * @param  array<array<string, mixed>>  $fonts
     * @return list<array{name: string, style: string, weight: string, src: string}>
     */
    private function localFonts(array $fonts): array
    {
        $resolver = resolve(FontMimeTypeResolverInterface::class);
        $localFonts = [];
        foreach ($fonts as $font) {
            if (($font['type'] ?? null) !== FontTypeEnum::Local->value) {
                continue;
            }

            if (! isset($font['files'])) {
                continue;
            }

            if (! is_array($font['files'])) {
                continue;
            }

            if ($font['files'] === []) {
                continue;
            }

            if (! isset($font['name'])) {
                continue;
            }

            $files = [];
            foreach ($font['files'] as $file) {
                $url = is_string($file) ? HeadContentSanitizer::cssUrl(asset('storage/' . $file)) : null;

                if ($url === null) {
                    continue;
                }

                $files[] = sprintf("url('%s') format('%s')", $url, $resolver->getFontFileType($file));
            }

            if ($files === []) {
                continue;
            }

            $localFonts[] = [
                'name' => HeadContentSanitizer::cssValue((string) $font['name'], 'sans-serif'),
                'style' => HeadContentSanitizer::cssValue((string) ($font['style'] ?? 'normal'), 'normal'),
                'weight' => HeadContentSanitizer::cssValue((string) ($font['weight'] ?? '400'), '400'),
                'src' => implode(', ', $files) . ';',
            ];
        }

        return $localFonts;
    }
}
