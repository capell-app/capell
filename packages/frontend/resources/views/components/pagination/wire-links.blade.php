@php
    $paginationRoute = app('paginateroute');
    $scrollTarget = $scrollTo ?? '#main';
@endphp

<nav
    class="capell-component capell-pagination-wire-links wire-pagination-links isolate inline-flex gap-1 rounded-lg dark:text-white"
    role="navigation"
    aria-label="{{ __('capell-frontend::generic.pagination') }}"
    wire:loading.class="opacity-60"
>
    {{-- Previous Page Link --}}
    @if (! $paginationRoute->hasPreviousPage($paginator))
        <span
            class="relative inline-flex items-center rounded-md px-2.5 py-2 text-gray-400 opacity-50 ring-1 ring-gray-300 ring-inset dark:text-gray-500 dark:ring-gray-600"
            aria-disabled="true"
            aria-label="{{ __('capell-frontend::generic.previous') }}"
            aria-hidden="true"
        >
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </span>
    @else
        <a
            href="{{ $paginationRoute->previousPageUrl() }}"
            class="relative inline-flex items-center rounded-md px-2.5 py-2 text-gray-500 ring-1 ring-gray-300 transition ring-inset hover:bg-gray-50 hover:text-gray-700 focus:z-20 focus:outline-offset-0 disabled:pointer-events-none disabled:opacity-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
            aria-label="{{ __('capell-frontend::generic.previous') }}"
            x-on:click="
                document
                    .querySelector(@js($scrollTarget))
                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
            "
            dusk="previousPage{{ $paginator->getPageName() === 'page' ? '' : '.' . $paginator->getPageName() }}.after"
            rel="prev"
            wire:navigate
        >
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </a>
    @endif

    {{-- Pagination Elements --}}
    @foreach ($elements as $element)
        {{-- "Three Dots" Separator --}}
        @if (is_string($element))
            <span
                class="relative inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-gray-500 ring-1 ring-gray-300 ring-inset focus:outline-offset-0 dark:text-gray-300 dark:ring-gray-600"
                aria-disabled="true"
            >
                {{ $element }}
            </span>
        @endif

        {{-- Array Of Links --}}
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page === $paginator->currentPage())
                    <span
                        class="bg-primary focus-visible:outline-primary dark:bg-primary-600 relative z-10 inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        aria-current="page"
                        wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}"
                    >
                        {{ $page }}
                    </span>
                @else
                    <a
                        href="{{ $paginationRoute->pageUrl($page) }}"
                        class="relative inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-gray-300 transition ring-inset hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:pointer-events-none disabled:opacity-50 dark:text-gray-100 dark:ring-gray-600 dark:hover:bg-gray-800"
                        aria-label="{{ __('capell-frontend::generic.go_to_page', ['page' => $page]) }}"
                        wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}"
                        x-on:click="
                            document
                                .querySelector(@js($scrollTarget))
                                ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
                        "
                        wire:navigate
                    >
                        {{ $page }}
                    </a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next Page Link --}}
    @if ($paginationRoute->hasNextPage($paginator))
        <a
            href="{{ $paginationRoute->nextPageUrl($paginator) }}"
            class="relative inline-flex items-center rounded-md px-2.5 py-2 text-gray-500 ring-1 ring-gray-300 transition ring-inset hover:bg-gray-50 hover:text-gray-700 focus:z-20 focus:outline-offset-0 disabled:pointer-events-none disabled:opacity-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
            aria-label="{{ __('capell-frontend::generic.next') }}"
            x-on:click="
                document
                    .querySelector(@js($scrollTarget))
                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
            "
            dusk="nextPage{{ $paginator->getPageName() === 'page' ? '' : '.' . $paginator->getPageName() }}.after"
            rel="next"
            wire:navigate
        >
            @svg('heroicon-o-chevron-right', 'h-5 w-5')
        </a>
    @else
        <span
            class="relative inline-flex items-center rounded-md px-2.5 py-2 text-gray-400 opacity-50 ring-1 ring-gray-300 ring-inset dark:text-gray-500 dark:ring-gray-600"
            aria-label="{{ __('capell-frontend::generic.next') }}"
            aria-disabled="true"
            aria-hidden="true"
        >
            @svg('heroicon-o-chevron-right', 'h-5 w-5')
        </span>
    @endif
</nav>
