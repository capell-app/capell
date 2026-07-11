@php
    use Capell\Frontend\Enums\RenderHookLocation;
    use Capell\Frontend\Support\Render\RenderHookRegistry;

    $footerBefore = app(RenderHookRegistry::class)->renderAll(RenderHookLocation::FooterBefore);
    $footerAfter = app(RenderHookRegistry::class)->renderAll(RenderHookLocation::FooterAfter);
@endphp

@if ($footerBefore !== '' || $footerAfter !== '')
    <footer
        class="capell-component capell-footer-index border-t border-slate-200/80 bg-white text-sm text-slate-500"
    >
        <div
            class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-6 py-8 lg:px-8"
        >
            {!! $footerBefore !!}
            {!! $footerAfter !!}
        </div>
    </footer>
@endif
