{{-- @blaze-standard-compiler --}}
@if ($renderData->isSystemPageLayout)
    <div
        {{ $attributes->merge(['class' => 'capell-component capell-layout-index capell-default-theme__layout flex min-h-screen flex-col']) }}
    >
        <main
            id="main"
            class="capell-component capell-layout-main mx-auto flex min-h-screen w-full max-w-3xl flex-col items-center justify-center px-6 py-12 text-center"
        >
            <a
                href="{{ $renderData->homeUrl }}"
                class="capell-default-theme__brand mb-10 inline-flex items-center justify-center text-lg font-medium"
            >
                @if ($renderData->logoView)
                    @include($renderData->logoView, ['class' => 'h-10 w-auto'])
                @elseif ($renderData->renderedMediaLogo !== null)
                    {!! $renderData->renderedMediaLogo !!}
                @else
                    <span>{{ $renderData->siteDisplayName }}</span>
                @endif
            </a>

            <x-capell::content
                :content="$renderData->pageContent"
                :content-type="$renderData->pageContentStructure"
                :title="$renderData->pageTitle"
                class="capell-default-theme__content mx-auto max-w-2xl"
                heading-tag="h1"
                heading-size="h1"
                text-align="center"
            />

            {{ $pageSlot ?? $slot }}
        </main>
    </div>
@else
    <div
        {{ $attributes->merge(['class' => 'capell-component capell-layout-index flex min-h-screen flex-col']) }}
    >
        <a
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-gray-900 focus:shadow"
            href="#main"
        >
            {{ __('capell-frontend::generic.skip_link') }}
        </a>

        @if ($header)
            {{ $header }}
        @elseif ($header === null && $renderData->headerFile !== null)
            @if ($renderData->headerFileIsView)
                {!! view($renderData->headerFile)->render() !!}
            @else
                <x-dynamic-component :component="$renderData->headerFile" />
            @endif
        @endif

        {{ $mainLayout($pageSlot ?? $slot) }}

        @if ($footer)
            {{ $footer }}
        @elseif ($footer === null && $renderData->footerFile !== null)
            @if ($renderData->footerFileIsView)
                {!! view($renderData->footerFile)->render() !!}
            @else
                <x-dynamic-component :component="$renderData->footerFile" />
            @endif
        @endif

        @if ($renderData->aiNotice !== null)
            <p
                class="capell-ai-notice mx-auto w-full max-w-7xl px-6 py-4 text-sm text-slate-500 lg:px-8"
            >
                {{ $renderData->aiNotice }}
            </p>
        @endif
    </div>
@endif
