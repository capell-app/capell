<main
    id="main"
    @class([
        'relative z-0 flex min-h-full flex-1 flex-col overflow-x-hidden lg:!min-h-0',
        'capell-component capell-layout-main',
        $renderData->mainClass,
    ])
>
    @if ($renderData->contentHtml !== '')
        @php echo $renderData->contentHtml; @endphp
    @endif

    @if ($renderData->slotHtml !== '')
        {!! $renderData->slotHtml !!}
    @endif

    {!! $renderData->afterContentHtml !!}
</main>
