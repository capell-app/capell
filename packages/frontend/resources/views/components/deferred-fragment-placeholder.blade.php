@props([
    'placeholder',
])

<div
    data-deferred-fragment
    data-deferred-fragment-key="{{ $placeholder->cacheKey }}"
    data-deferred-fragment-url="{{ $placeholder->url }}"
    data-deferred-fragment-strategy="{{ $placeholder->strategy }}"
    data-cr-skel="{{ $placeholder->variant }}"
    aria-busy="true"
    @if ($placeholder->minHeight)
        style="min-height: {{ $placeholder->minHeight }}"
    @endif
></div>
