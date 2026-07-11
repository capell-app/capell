<template x-teleport="#capell-marketplace-browser-modal-footer">
    <button
        type="button"
        x-on:click="reviewMarketplaceSelection()"
        wire:loading.attr="disabled"
        wire:target="showMarketplaceInstallReview"
        x-bind:disabled="selectedCount() === 0"
        x-bind:title="selectedCount() === 0 ? @js(__('capell-marketplace::marketplace.selection.install_button_disabled_tooltip')) : null"
        x-bind:aria-label="selectedCount() === 0 ? @js(__('capell-marketplace::marketplace.selection.install_button_disabled_tooltip')) : null"
        class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 enabled:bg-blue-600 enabled:text-white enabled:hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 dark:enabled:bg-blue-500 dark:enabled:hover:bg-blue-400 dark:disabled:bg-white/10 dark:disabled:text-gray-500"
    >
        <span
            wire:loading.remove
            wire:target="showMarketplaceInstallReview"
        >
            {{ __('capell-marketplace::marketplace.selection.install_footer_action') }}
        </span>

        <span
            wire:loading.flex
            wire:target="showMarketplaceInstallReview"
            class="hidden items-center gap-2"
        >
            <x-filament::loading-indicator class="h-4 w-4" />
            {{ __('capell-marketplace::marketplace.selection.reviewing_button') }}
        </span>
    </button>
</template>
