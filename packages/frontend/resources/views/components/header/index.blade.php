@inject('renderData', 'Capell\Frontend\Data\PublicHeaderData')
@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Support\Render\RenderHookRegistry;
@endphp

<header
    class="capell-component capell-header-index border-b border-slate-200/80 bg-white text-slate-950"
>
    <div
        class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-6 py-4 lg:px-8"
    >
        <a
            class="inline-flex min-w-0 items-center gap-3 text-base leading-none font-semibold text-slate-950 transition hover:text-blue-700 focus-visible:text-blue-700"
            href="{{ $renderData->homeUrl }}"
        >
            @if ($renderData->logoView)
                @include($renderData->logoView, ['class' => 'h-10 w-auto'])
            @elseif ($renderData->renderedMediaLogo !== null)
                {!! $renderData->renderedMediaLogo !!}
            @else
                <span class="truncate">
                    {{ $renderData->siteDisplayName }}
                </span>
            @endif
        </a>

        {!!
            app(RenderHookRegistry::class)->renderAll(
                RenderHookLocation::HeaderAfter,
                scenario: 'frontend-default-primary-navigation',
                target: 'capell::header.index',
            )
        !!}
    </div>
</header>
