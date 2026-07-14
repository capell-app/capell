@props([
    'title' => '',
    'livewireEnabled' => true,
    'runtimeManifest' => null,
    'resourcePlan' => null,
])

{{-- format-ignore-start --}}
@php
    use Capell\Core\Enums\MediaConversionEnum;
    use Capell\Frontend\Actions\ResolvePageCanonicalUrlAction;
    use Capell\Frontend\Actions\ResolvePageRobotsDirectivesAction;
    use Capell\Frontend\Contracts\FontMimeTypeResolverInterface;
    use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
    use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
    use Capell\Frontend\Data\ColorData;
    use Capell\Frontend\Data\FrontendResourceContextData;
    use Capell\Frontend\Data\FrontendRuntimeManifestData;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Actions\GetCriticalCssContentAction;
    use Capell\Frontend\Actions\GetPageVariablesAction;
    use Capell\Frontend\Actions\GetThemeColorsAction;
    use Capell\Frontend\Enums\RenderingStrategyEnum;
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Enums\RenderHookScenario;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
    use Capell\Frontend\Support\Security\HeadContentSanitizer;
    use Capell\Frontend\Support\View\PublicModelMeta;

    $theme = Frontend::theme();
    $site = Frontend::site();
    $language = Frontend::language();
    $layout = Frontend::layout();
    $page = Frontend::page();
    $themeMeta = $theme?->meta ?? [];
    $siteMeta = $site?->meta ?? [];
    $pageMeta = $page?->meta ?? [];
    $pageTranslation = $page !== null && method_exists($page, 'relationLoaded') && $page->relationLoaded('translation') ? $page->translation : null;
    $siteTranslation = $site !== null && method_exists($site, 'relationLoaded') && $site->relationLoaded('translation') ? $site->translation : null;
    $siteDomain = $site !== null && method_exists($site, 'relationLoaded') && $site->relationLoaded('siteDomain') ? $site->siteDomain : null;
    $pageUrl = $page !== null && method_exists($page, 'relationLoaded') && $page->relationLoaded('pageUrl') ? $page->pageUrl : null;
    $pageUrls = $page !== null && method_exists($page, 'relationLoaded') && $page->relationLoaded('pageUrls') ? $page->pageUrls : collect();
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
        } catch (\Throwable) {
            return null;
        }
    };
    $title = $stringValue($title) ?? '';

    $fontResolver = resolve(FontMimeTypeResolverInterface::class);

    if ($title === '') {
        $translationMetaTitle = $stringValue(data_get($pageTranslation?->meta ?? [], 'title'))
            ?? $safeStringAttribute($pageTranslation, 'meta_title');

        if ($translationMetaTitle !== null && $translationMetaTitle !== '') {
            $title = $translationMetaTitle;
        } else {
            $title = '';
            $pageTitle = $safeStringAttribute($pageTranslation, 'title');

            if ($pageTitle !== null && $pageTitle !== '') {
                $title = $pageTitle;
            }

            $append = $stringValue(data_get($siteTranslation?->meta ?? [], 'title_after_text'))
                ?? $safeStringAttribute($siteTranslation, 'title');

            if ($append !== null && $append !== '' && $append !== $title) {
                $title .= config('capell-frontend.meta_title_seperator', ' ') . $append;
            }
        }
    }

    $description = $safeStringAttribute($pageTranslation, 'meta_description');

    $keywords = $safeStringAttribute($pageTranslation, 'meta_keywords');

    $fonts = PublicModelMeta::get($theme, 'fonts');

    $runtimeManifest = $runtimeManifest
        ?? Frontend::getFrontendData('runtimeManifest')
        ?? FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $resourcePlan = $resourcePlan ?? Frontend::getFrontendData('resourcePlan');
    $renderedFrontendResources = Frontend::getFrontendData('renderedFrontendResources');

    if (! $renderedFrontendResources instanceof \Capell\Frontend\Data\Assets\RenderedFrontendResourcesData && $resourcePlan instanceof FrontendResourcePlanData) {
        $renderedFrontendResources = app(FrontendResourcePlanRenderer::class)->render($resourcePlan, new FrontendResourceContextData(
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
    $defaultAlternateUrl = $pageUrls->firstWhere('language_id', $site->language_id)?->full_url
        ?? $pageUrl?->full_url
        ?? $siteDomain?->full_url;
    $pageVariables = GetPageVariablesAction::run($page, $site);
    $translationVariables = collect($pageVariables)
        ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof Stringable)
        ->map(fn (mixed $value): string => (string) $value)
        ->all();

@endphp
{{-- format-ignore-end --}}
<head>
    <meta charset="utf-8" />

    {{-- format-ignore-start --}}
    <title>
        @yield('meta_title', trans(strip_tags($title), $translationVariables))
    </title>
    {{-- format-ignore-end --}}

    <base href="{{ $siteDomain?->full_url }}" />

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    />

    {{-- format-ignore-start --}}
    @if ($fonts)
        @php
            $isGoogleFont = false;
            $fontUrls = [];
            foreach ($fonts as $font) {
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
        @endphp
        @if ($isGoogleFont)
            <link
                    rel="preconnect"
                    href="https://fonts.googleapis.com"
            />
            <link
                    rel="preconnect"
                    href="https://fonts.gstatic.com"
                    crossorigin
            />
        @endif

        @foreach ($fontUrls as $fontUrl)
            <link
                    href="{{ $fontUrl }}"
                    rel="stylesheet"
            />
        @endforeach
    @endif
    {{-- format-ignore-end --}}

    @if ($renderedFrontendResources instanceof RenderedFrontendResourcesData)
        {!! $renderedFrontendResources->headHtml !!}
    @endif

    @foreach ($mediaHints as $mediaHint)
        <link
            rel="preload"
            as="{{ $mediaHint->as }}"
            href="{{ $mediaHint->url }}"
            fetchpriority="{{ $mediaHint->fetchPriority }}"
            @if ($mediaHint->imageSrcset)
                imagesrcset="{{ $mediaHint->imageSrcset }}"
            @endif
            @if ($mediaHint->imageSizes)
                imagesizes="{{ $mediaHint->imageSizes }}"
            @endif
            @if ($mediaHint->mimeType)
                type="{{ $mediaHint->mimeType }}"
            @endif
        />
    @endforeach

    @if ($canonicalUrl)
        <link
            href="{{ $canonicalUrl }}"
            rel="canonical"
        />
    @endif

    @if ($icon)
        <link
            href="{{ asset('storage/' . $icon) }}"
            rel="apple-touch-icon"
            sizes="180x180"
        />
    @endif

    @if ($favicon)
        <link
            href="{{ asset('storage/' . $favicon) }}"
            rel="icon"
        />
    @endif

    @if ($color)
        <link
            href="/safari-pinned-tab.svg"
            rel="mask-icon"
            color="{{ $color }}"
        />
        <meta
            name="msapplication-TileColor"
            content="{{ $color }}"
        />
        <meta
            name="theme-color"
            content="{{ $color }}"
        />
    @endif

    @foreach ($pageUrls as $url)
        @php
            $urlLanguage = $url->relationLoaded('language') && $url->language
                ? $url->language
                : ($url->language_id === $language->id ? $language : null);

            $alternateSiteDomain = $url->relationLoaded('siteDomain') ? $url->siteDomain : null;

            if (! $alternateSiteDomain?->full_url || ! $urlLanguage) {
                continue;
            }
        @endphp

        <link
            href="{{ $url->full_url }}"
            hreflang="{{ Str::of($urlLanguage->locale)->lower()->replace('_', '-') }}"
            rel="alternate"
        />
    @endforeach

    @if ($defaultAlternateUrl)
        <link
            href="{{ $defaultAlternateUrl }}"
            hreflang="x-default"
            rel="alternate"
        />
    @endif

    @if (PublicModelMeta::get($theme, 'critical_asset') && PublicModelMeta::get($theme, 'assets_path'))
        <style>
            {!!
                HeadContentSanitizer::css(wordwrap(
                    GetCriticalCssContentAction::run(PublicModelMeta::get($theme, 'critical_asset'), PublicModelMeta::get($theme, 'assets_path')),
                ))
            !!}
        </style>
    @endif

    {{-- format-ignore-start --}}
    <style>
        :root {
            --font-family: {!! HeadContentSanitizer::cssValue(PublicModelMeta::get($theme, 'font_family'), 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"') !!};
            --font-family-heading: {!! HeadContentSanitizer::cssValue(PublicModelMeta::get($theme, 'font_heading_family'), 'var(--font-family)') !!};
            --livewire-progress-bar-color: var(--color-primary, #0f766e);
        {{
            collect($colors)
                ->map(fn (ColorData $color): string => "--color-$color->name:" . $color->getColor() . ';')
                ->implode("\n")
        }}


        }
        #nprogress {
            pointer-events: none;
        }

        #nprogress .bar {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            width: 100%;
            height: 0.1875rem;
            background: var(--livewire-progress-bar-color);
            transform-origin: left center;
        }

        #nprogress .peg {
            display: block;
            position: absolute;
            right: 0;
            width: 6rem;
            height: 100%;
            box-shadow: 0 0 0.875rem var(--livewire-progress-bar-color);
            opacity: 0.45;
            transform: rotate(3deg) translate(0, -0.125rem);
        }
        {!! HeadContentSanitizer::css((string) ($theme->custom_css ?? '')) !!}
    </style>
    @if ($fonts)
        @foreach ($fonts as $themeFont)
            @php
                if (($themeFont['type'] ?? null) === \Capell\Core\Enums\FontTypeEnum::Local->value && isset($themeFont['files']) && is_array($themeFont['files']) && !empty($themeFont['files']) && isset($themeFont['name'])) {
                    $src = collect($themeFont['files'])->map(
                        function (string $fontFile, int $index) use ($fontResolver, $themeFont): string {
                            $url = asset('storage/' . $fontFile);
                            $format = $fontResolver->getFontFileType($fontFile);
                            $isLast = $index === array_key_last($themeFont['files']);

                            return sprintf("url('%s') format('%s')", $url, $format) . ($isLast ? ';' : ',');
                        },
                    )->implode(' ');

                    $fontName = HeadContentSanitizer::cssValue((string) $themeFont['name'], 'sans-serif');
                    $fontStyle = HeadContentSanitizer::cssValue((string) ($themeFont['style'] ?? 'normal'), 'normal');
                    $fontWeight = HeadContentSanitizer::cssValue((string) ($themeFont['weight'] ?? '400'), '400');

                    echo <<<CSS
                       <style>
                           @font-face {
                               font-family: '{$fontName}';
                               src: {$src};
                               font-style: '{$fontStyle}';
                               font-weight: '{$fontWeight}';
                               font-display: swap;
                           }
                       </style>
                       CSS;
                }
            @endphp
        @endforeach
    @endif
    {{-- format-ignore-end --}}
    @stack('styles')

    @if ($runtimeManifest?->usesLivewire ?? $livewireEnabled)
        @livewireStyles
    @endif

    @if ($description)
        <meta
            name="description"
            content="{{ strip_tags($description) }}"
        />
    @endif

    @if ($keywords)
        <meta
            name="keywords"
            content="{{ strip_tags($keywords) }}"
        />
    @endif

    @if ($robots)
        <meta
            name="robots"
            content="{{ implode(', ', $robots) }}"
        />
    @endif

    {!!
        app(RenderHookRegistry::class)->renderAll(
            RenderHookLocation::HeadClose,
            item: ['page' => $page, 'site' => $site, 'language' => $language],
            scenario: RenderHookScenario::SeoMeta->value,
        )
    !!}

    {!! HeadContentSanitizer::headSnippet(data_get($siteMeta, 'meta_tags')) !!}

    {!! HeadContentSanitizer::headSnippet(PublicModelMeta::get($theme, 'meta_tags')) !!}

    {!! HeadContentSanitizer::headSnippet(data_get($pageMeta, 'meta_tags')) !!}

    <x-capell::app.head.custom
        :title="$title"
        :description="$description"
        :keywords="$keywords"
    />

    @yield('meta')
</head>
