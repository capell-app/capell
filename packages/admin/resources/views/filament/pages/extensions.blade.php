<x-filament-panels::page>
    <div
        class="@container/capell-extensions-dashboard space-y-6"
        wire:open-marketplace.window="mountAction('openMarketplace')"
        wire:open-marketplace-install-operations.window="mountAction('marketplaceInstallOperations')"
        data-capell-extensions-dashboard
    >
        {{ $this->content }}

        @if (session('capell-marketplace.open-marketplace'))
            <div
                class="hidden"
                x-data
                x-init="$nextTick(() => $wire.mountAction('openMarketplace'))"
            ></div>
        @endif
    </div>
</x-filament-panels::page>
