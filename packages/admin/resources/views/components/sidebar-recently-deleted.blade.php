<div class="fi-sidebar-footer-recently-deleted px-6 pb-4">
    <a
        href="{{ $url }}"
        class="flex items-center gap-x-3 rounded-lg px-2 py-2 text-sm font-medium text-gray-600 outline-none transition duration-75 hover:bg-gray-100 focus-visible:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
    >
        <x-filament::icon
            icon="heroicon-o-trash"
            class="h-5 w-5 text-gray-400 dark:text-gray-500"
        />
        <span>{{ __('capell-admin::generic.recently_deleted') }}</span>
    </a>
</div>
