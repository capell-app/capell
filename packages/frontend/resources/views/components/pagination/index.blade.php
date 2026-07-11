@props([
    'results',
    'scrollToElement' => 'body',
    'wireLinks' => true,
    'withLinks' => true,
    'withSummary' => true,
])
@if ($results && method_exists($results, 'links'))
    @if ($results->hasPages())
        <div
            {{ $attributes->merge(['class' => 'capell-component capell-pagination-index pagination'])->only('class') }}
        >
            @if ($withLinks)
                <div class="flex-1 md:hidden">
                    {{
                        $results->links(
                            view: $wireLinks
                        ? 'capell::components.pagination.wire-simple-links'
                        : 'capell::components.pagination.simple-links',
                            data: ['scrollTo' => $scrollToElement],
                        )
                    }}
                </div>
                <div class="hidden flex-1 space-y-6 text-center md:block">
                    {{
                        $results->links(
                            view: $wireLinks
                        ? 'capell::components.pagination.wire-links'
                        : 'capell::components.pagination.links',
                            data: ['scrollTo' => $scrollToElement],
                        )
                    }}
                </div>
            @endif

            @if ($withSummary)
                <x-capell::pagination.summary :$results />
            @endif
        </div>
    @endif
@endif
