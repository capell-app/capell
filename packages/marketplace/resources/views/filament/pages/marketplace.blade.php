@php
    use Capell\Admin\Filament\Pages\ExtensionsPage;
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <div
            class="flex flex-col gap-4 border-b border-gray-200 pb-5 md:flex-row md:items-end md:justify-between dark:border-white/10"
        >
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-wide text-blue-600 uppercase dark:text-blue-400">
                    {{ __('capell-marketplace::marketplace.page.eyebrow') }}
                </p>
                <h1
                    class="mt-1 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.page.heading') }}
                </h1>
                <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                    {{ __('capell-marketplace::marketplace.page.description') }}
                </p>
            </div>

            <div
                class="flex w-full items-center gap-1 overflow-x-auto border-b border-gray-200 md:w-auto dark:border-white/10"
            >
                <span
                    class="border-b-2 border-blue-600 px-4 py-2 text-sm font-semibold text-blue-700 dark:text-blue-300"
                >
                    {{ __('capell-marketplace::marketplace.page.tabs.marketplace') }}
                </span>
                <a
                    href="{{ ExtensionsPage::getUrl() }}"
                    class="border-b-2 border-transparent px-4 py-2 text-sm font-medium text-gray-500 transition hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-300"
                >
                    {{ __('capell-marketplace::marketplace.page.tabs.installed') }}
                </a>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0">
                <livewire:capell-marketplace.marketplace-extensions-browser
                    :include-local-extension-state="\Capell\Admin\Filament\Pages\ExtensionsPage::canAccess()"
                    :initial-search="request()->query('tableSearch')"
                />

                <div
                    id="capell-marketplace-browser-modal-footer"
                    class="mt-4 flex justify-end"
                ></div>
            </div>

            <aside class="space-y-4">
                @include('capell-marketplace::filament.pages.extensions-page-marketplace-status', [
                    'marketplaceConnection' => $this->marketplaceConnection(),
                    'marketplaceConnectionActionsVisible' => $this->marketplaceConnection()->canManageConnectionActions(),
                    'marketplaceConnectionButtonsVisible' => $this->marketplaceConnection()->canManageConnectionActions(),
                    'marketplaceConnectionDetailsVisible' => $this->marketplaceConnection()->canViewConnectionDetails(),
                ])
            </aside>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
