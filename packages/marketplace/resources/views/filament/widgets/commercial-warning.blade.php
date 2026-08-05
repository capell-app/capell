<x-filament-widgets::widget>
    @if ($this->warnings() !== [])
        <x-filament::section>
            <div
                class="space-y-3"
                data-capell-marketplace-commercial-warning
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <h2
                            class="text-base font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.purchases.warning.heading') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('capell-marketplace::marketplace.purchases.warning.summary') }}
                        </p>
                    </div>

                    <x-filament::button
                        tag="a"
                        :href="$this->purchasesUrl()"
                        size="sm"
                    >
                        {{ __('capell-marketplace::marketplace.purchases.warning.view') }}
                    </x-filament::button>
                </div>

                @foreach ($this->warnings() as $warning)
                    <p
                        class="text-sm text-gray-700 dark:text-gray-200"
                        data-capell-marketplace-commercial-warning-item="{{ $warning->key }}"
                    >
                        {{ __('capell-marketplace::marketplace.purchases.warning.' . $warning->status . '.body', [
                            'name' => $warning->name,
                            'date' => $warning->accessEndsAt?->translatedFormat('M j, Y'),
                        ]) }}
                    </p>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
