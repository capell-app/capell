<div>
    <nav
        class="capell-component capell-pagination-wire-simple-links flex justify-between dark:text-white"
        role="navigation"
        aria-label="{{ __('capell-frontend::generic.pagination') }}"
    >
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span
                class="relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400"
            >
                {{ __('capell-frontend::generic.previous') }}
            </span>
        @else
            <button
                class="focus:shadow-outline-blue relative inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm leading-5 font-medium text-gray-700 transition duration-150 ease-in-out hover:text-gray-500 focus:border-blue-300 focus:outline-hidden active:bg-gray-100 active:text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:text-gray-200 dark:active:bg-gray-700"
                type="button"
                wire:click="previousPage('{{ $paginator->getPageName() }}')"
                wire:loading.attr="disabled"
                dusk="previousPage{{ $paginator->getPageName() === 'page' ? '' : '.' . $paginator->getPageName() }}"
            >
                {{ __('capell-frontend::generic.previous') }}
            </button>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <button
                class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                type="button"
                wire:click="nextPage('{{ $paginator->getPageName() }}')"
                wire:loading.attr="disabled"
                dusk="nextPage{{ $paginator->getPageName() === 'page' ? '' : '.' . $paginator->getPageName() }}"
            >
                {{ __('capell-frontend::generic.next') }}
            </button>
        @else
            <span
                class="relative inline-flex cursor-default items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm leading-5 font-medium text-gray-500 select-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-500"
            >
                {{ __('capell-frontend::generic.next') }}
            </span>
        @endif
    </nav>
</div>
