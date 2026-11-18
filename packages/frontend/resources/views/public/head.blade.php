<head>
    <meta charset="utf-8" />

    {!! $renderData->headOpenHook !!}
    <title>{{ $renderData->metaTitle }}</title>
    @foreach ($renderData->metadata as $key => $value)
        <meta
            {{ str_starts_with($key, 'og:') ? 'property' : 'name' }}="{{ $key }}"
            content="{{ $value }}"
        />
    @endforeach
    {{-- format-ignore-start --}}
    <script
        type="application/ld+json"
    >{!! $renderData->jsonLd !!}</script>
    {{-- format-ignore-end --}}


    <base href="{{ $renderData->baseUrl }}" />

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    />

    @if ($renderData->fontUrls)
        @if ($renderData->hasGoogleFont)
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

        @foreach ($renderData->fontUrls as $fontUrl)
            <link
                href="{{ $fontUrl }}"
                rel="stylesheet"
            />
        @endforeach
    @endif

    {!! $renderData->resourceHead !!}

    @foreach ($renderData->mediaHints as $mediaHint)
        <link
            rel="preload"
            as="{{ $mediaHint['as'] }}"
            href="{{ $mediaHint['url'] }}"
            fetchpriority="{{ $mediaHint['fetchPriority'] }}"
            @if ($mediaHint['imageSrcset'])
                imagesrcset="{{ $mediaHint['imageSrcset'] }}"
            @endif
            @if ($mediaHint['imageSizes'])
                imagesizes="{{ $mediaHint['imageSizes'] }}"
            @endif
            @if ($mediaHint['mimeType'])
                type="{{ $mediaHint['mimeType'] }}"
            @endif
        />
    @endforeach

    @if ($renderData->canonicalUrl)
        <link
            href="{{ $renderData->canonicalUrl }}"
            rel="canonical"
        />
    @endif

    @if ($renderData->iconUrl)
        <link
            href="{{ $renderData->iconUrl }}"
            rel="apple-touch-icon"
            sizes="180x180"
        />
    @endif

    @if ($renderData->faviconUrl)
        <link
            href="{{ $renderData->faviconUrl }}"
            rel="icon"
        />
    @endif

    @if ($renderData->color)
        <link
            href="/safari-pinned-tab.svg"
            rel="mask-icon"
            color="{{ $renderData->color }}"
        />
        <meta
            name="msapplication-TileColor"
            content="{{ $renderData->color }}"
        />
        <meta
            name="theme-color"
            content="{{ $renderData->color }}"
        />
    @endif

    @foreach ($renderData->alternates as $alternate)
        <link
            href="{{ $alternate['href'] }}"
            hreflang="{{ $alternate['hreflang'] }}"
            rel="alternate"
        />
    @endforeach

    @if ($renderData->defaultAlternateUrl)
        <link
            href="{{ $renderData->defaultAlternateUrl }}"
            hreflang="x-default"
            rel="alternate"
        />
    @endif

    @if ($renderData->criticalCss !== null)
        <style>
            {!! $renderData->criticalCss !!}
        </style>
    @endif

    <style>
        :root {
            --font-family: {!! $renderData->fontFamily !!};
            --font-family-heading: {!! $renderData->headingFontFamily !!};
            --livewire-progress-bar-color: var(--color-primary, #0f766e);
        {{
            collect($renderData->colors)
                ->map(fn (array $color): string => "--color-{$color['name']}:" . $color['color'] . ';')
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
        {!! $renderData->customCss !!}
    </style>
    @foreach ($renderData->localFonts as $themeFont)
    @php
            echo <<<CSS
                <style>
                    @font-face {
                        font-family: '{$themeFont['name']}';
                        src: {$themeFont['src']};
                        font-style: '{$themeFont['style']}';
                        font-weight: '{$themeFont['weight']}';
                        font-display: swap;
                    }
                </style>
                CSS;
        @endphp
    @endforeach
    {!! $renderData->stylesHead !!}

    @if ($renderData->usesLivewire)
        @livewireStyles
    @endif

    @if ($renderData->description)
        <meta
            name="description"
            content="{{ strip_tags($renderData->description) }}"
        />
    @endif

    @if ($renderData->keywords)
        <meta
            name="keywords"
            content="{{ strip_tags($renderData->keywords) }}"
        />
    @endif

    @if ($renderData->aiDisclosure !== null)
        <meta
            name="ai-disclosure"
            content="{{ $renderData->aiDisclosure }}"
        />
    @endif

    {!! $renderData->headHook !!}
    {!! $renderData->siteHead !!}
    {!! $renderData->themeHead !!}
    {!! $renderData->pageHead !!}
    {!! $renderData->customHead !!}
    {!! $renderData->sectionHead !!}
</head>
