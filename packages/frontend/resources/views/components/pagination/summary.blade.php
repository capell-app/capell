@props([
    'results' => '',
    'resultsFoundText' => __('capell-frontend::messages.results_found'),
])

@php
    if (! $results || (method_exists($results, 'total') ? $results->total() === 0 : $results->isEmpty())) {
        return;
    }
@endphp

<div
    {{
        $attributes
            ->merge([
                'aria-label' => __('capell-frontend::messages.pagination_info', [
                    'from' => ($results->currentPage() - 1) * $results->perPage() + 1,
                    'to' => ($results->currentPage() - 1) * $results->perPage() + count($results->items()),
                    'total' => $results->total(),
                ]),
            ])
            ->class('capell-component capell-pagination-summary pagination-info text-sm leading-6 font-normal text-gray-500 dark:text-gray-400')
    }}
>
    @if ($results->perPage() < $results->total())
        {{ __('capell-frontend::messages.showing') }}
        <span
            class="pagination-range font-semibold tracking-normal dark:text-white"
        >
            {{ ($results->currentPage() - 1) * $results->perPage() + 1 }} to
            {{ ($results->currentPage() - 1) * $results->perPage() + count($results->items()) }}
        </span>
        {{ __('capell-frontend::messages.of') }}
        <span
            class="pagination-total font-semibold tracking-normal dark:text-white"
        >
            {{ $results->total() }}
        </span>
        {{ $resultsFoundText }}
    @else
        {{ __('capell-frontend::messages.showing') }}
        <span
            class="pagination-total font-semibold tracking-normal dark:text-white"
        >
            {{ $results->total() }}
        </span>
        {{ $resultsFoundText }}
    @endif
</div>
