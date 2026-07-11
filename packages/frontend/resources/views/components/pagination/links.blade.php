<nav
    class="capell-component capell-pagination-links pagination-links isolate inline-flex -space-x-px rounded-md dark:text-white"
    role="navigation"
    aria-label="{{ __('capell-frontend::generic.pagination') }}"
>
    {{-- Previous Page Link --}}
    @if (app('paginateroute')->hasPreviousPage($paginator))
        <a
            class="pagination-links__link pagination-links__prev relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring ring-gray-300 ring-inset hover:bg-gray-50 focus:z-20 focus:outline-offset-0 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
            href="{{ app('paginateroute')->previousPageUrl() }}"
            aria-label="{{ __('capell-frontend::generic.previous') }}"
            rel="prev"
            @wireNavigate
        >
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </a>
    @else
        <span
            class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 opacity-50 ring ring-gray-300 ring-inset dark:text-gray-500 dark:ring-gray-600"
            aria-disabled="true"
            aria-label="{{ __('capell-frontend::generic.previous') }}"
            aria-hidden="true"
        >
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </span>
    @endif

    {{-- Pagination Elements --}}
    @foreach ($elements as $element)
        {{-- "Three Dots" Separator --}}
        @if (is_string($element))
            <span
                class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-700 ring ring-gray-300 ring-inset focus:outline-offset-0 dark:text-gray-300 dark:ring-gray-600"
                aria-disabled="true"
            >
                {{ $element }}
            </span>
        @endif

        {{-- Array Of Links --}}
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page === app('paginateroute')->currentPage())
                    <span
                        class="bg-primary focus-visible:outline-primary dark:bg-primary-600 relative z-10 inline-flex items-center px-4 py-2 text-sm font-semibold text-white focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        aria-current="page"
                    >
                        {{ $page }}
                    </span>
                @else
                    <a
                        class="pagination-links__link relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring ring-gray-300 ring-inset hover:bg-gray-50 focus:z-20 focus:outline-offset-0 dark:text-gray-100 dark:ring-gray-600 dark:hover:bg-gray-800"
                        href="{{ app('paginateroute')->pageUrl($page) }}"
                        aria-label="{{ __('capell-frontend::generic.go_to_page', ['page' => $page]) }}"
                        @wireNavigate
                    >
                        {{ $page }}
                    </a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next Page Link --}}
    @if (app('paginateroute')->hasNextPage($paginator))
        <a
            class="pagination-links__link pagination-links__next relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring ring-gray-300 ring-inset hover:bg-gray-50 focus:z-20 focus:outline-offset-0 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
            href="{{ app('paginateroute')->nextPageUrl($paginator) }}"
            aria-label="{{ __('capell-frontend::generic.next') }}"
            rel="next"
            @wireNavigate
        >
            @svg('heroicon-o-chevron-right', 'h-5 w-5')
        </a>
    @else
        <span
            class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 opacity-50 ring ring-gray-300 ring-inset dark:text-gray-500 dark:ring-gray-600"
            aria-label="{{ __('capell-frontend::generic.next') }}"
            aria-disabled="true"
            aria-hidden="true"
        >
            @svg('heroicon-o-chevron-right', 'h-5 w-5')
        </span>
    @endif
</nav>
