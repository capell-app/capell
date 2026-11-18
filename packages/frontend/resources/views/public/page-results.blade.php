<div
    {{ $attributes->merge(['class' => 'capell-component capell-page-results results @container']) }}
>
    <p class="sr-only" role="status" aria-live="polite">
        {{ $renderData->resultCount }} {{ __('capell-frontend::messages.results_found') }}
    </p>
    @if ($renderData->empty)
        {!! $renderData->renderedNoResults !!}
    @else
        <div
            class="grid w-full max-w-full min-w-0 gap-5 overflow-hidden @3xl:grid-cols-2"
            role="list"
        >
            {!! $renderData->renderedItems !!}
        </div>
    @endif
    {!! $renderData->renderedPagination !!}
</div>
