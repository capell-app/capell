@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\Render\RenderHookRegistry;

    $site = Frontend::site();
    $siteDomain = $site->relationLoaded('siteDomain') ? $site->siteDomain : null;
    $logoBladeView = $site->getMeta('logo_blade_view', 'brand.capell-logo');
    $logoBladeView = is_string($logoBladeView) && view()->exists($logoBladeView)
        ? $logoBladeView
        : null;
    $logo = $site->relationLoaded('logo') ? $site->logo : null;
    $translation = $site->relationLoaded('translation') ? $site->translation : null;
@endphp

<header
    class="capell-component capell-header-index border-b border-slate-200/80 bg-white text-slate-950"
>
    <div
        class="mx-auto flex w-full max-w-7xl items-center justify-between gap-6 px-6 py-4 lg:px-8"
    >
        <a
            class="inline-flex min-w-0 items-center gap-3 text-base leading-none font-semibold text-slate-950 transition hover:text-blue-700 focus-visible:text-blue-700"
            href="{{ $siteDomain?->url ?? '/' }}"
        >
            @if ($logoBladeView)
                @include($logoBladeView, ['class' => 'h-10 w-auto'])
            @elseif ($logo)
                <x-capell::logo :media="$logo" />
            @else
                <span class="truncate">
                    {{ $translation?->title ?? $site->name }}
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
