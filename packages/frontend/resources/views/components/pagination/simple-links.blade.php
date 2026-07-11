<div>
    <nav
        class="capell-component capell-pagination-simple-links flex justify-between gap-3 dark:text-white"
        role="navigation"
        aria-label="{{ __('capell-frontend::generic.pagination') }}"
    >
        {{-- Previous Page Link --}}
        @if (app('paginateroute')->hasPreviousPage($paginator))
            <a
                href="{{ app('paginateroute')->previousPageUrl() }}"
                class="focus:shadow-outline-blue relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm leading-5 font-medium text-gray-700 transition duration-150 ease-in-out hover:text-gray-500 focus:border-blue-300 focus:outline-hidden active:bg-gray-100 active:text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-gray-200 dark:active:bg-gray-700"
                rel="prev"
            >
                {{ __('capell-frontend::generic.previous') }}
            </a>
        @endif

        {{-- Next Page Link --}}
        @if (app('paginateroute')->hasNextPage($paginator))
            <a
                href="{{ app('paginateroute')->nextPageUrl($paginator) }}"
                class="relative ml-auto inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                rel="next"
            >
                {{ __('capell-frontend::generic.next') }}
            </a>
        @endif
    </nav>
</div>
