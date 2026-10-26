<x-filament-panels::page>
    @php($data = $this->purchasesData())

    <div
        class="space-y-6"
        data-capell-marketplace-purchases
    >
        <header>
            <h1 class="text-2xl font-semibold text-gray-950 dark:text-white">
                {{ __('capell-marketplace::marketplace.purchases.heading') }}
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __('capell-marketplace::marketplace.purchases.description') }}
            </p>
        </header>

        <div
            data-capell-account-snapshot="{{ $this->refreshFailed ? 'retained' : $data['snapshot_state'] }}"
        >
            @if ($this->refreshFailed)
                <p>{{ __('capell-marketplace::marketplace.purchases.snapshot.retained') }}</p>
            @elseif ($data['snapshot_state'] === 'not_connected')
                <p>{{ __('capell-marketplace::marketplace.purchases.snapshot.not_connected') }}</p>
            @elseif ($data['snapshot_state'] === 'not_observed')
                <p>{{ __('capell-marketplace::marketplace.purchases.snapshot.not_observed') }}</p>
            @else
                <p>{{ __('capell-marketplace::marketplace.purchases.snapshot.current') }}</p>
            @endif
            @if ($data['observed_at'])
                <p>{{ __('capell-marketplace::marketplace.purchases.observed', ['date' => $data['observed_at']]) }}</p>
            @endif
        </div>

        <x-filament::section
            :heading="__('capell-marketplace::marketplace.purchases.account_heading')"
        >
            <div class="space-y-3">
                @forelse ($data['purchases'] as $purchase)
                    <article
                        class="rounded-lg border border-gray-200 p-4 dark:border-white/10"
                        data-capell-marketplace-purchase
                    >
                        <p class="font-semibold text-gray-950 dark:text-white">{{ data_get($purchase, 'name') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('capell-marketplace::marketplace.purchases.status', ['status' => data_get($purchase, 'status', 'unknown')]) }}
                        </p>
                        @if (data_get($purchase, 'access_ends_at'))
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ __('capell-marketplace::marketplace.purchases.access_ends', ['date' => \Carbon\CarbonImmutable::parse((string) data_get($purchase, 'access_ends_at'))->translatedFormat('M j, Y')]) }}
                            </p>
                        @endif
                    </article>
                @empty
                    @if ($data['snapshot_state'] === 'current')
                        <p data-capell-marketplace-purchases-empty>{{ __('capell-marketplace::marketplace.purchases.empty') }}</p>
                    @endif
                @endforelse
            </div>
        </x-filament::section>

        @if ($data['priority_support_price'] !== null)
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('capell-marketplace::marketplace.purchases.priority_support', ['price' => $data['priority_support_price']]) }}
            </p>
        @endif

        @if ($data['expired_explanation'] !== null)
            <p class="text-sm text-gray-600 dark:text-gray-300" data-capell-marketplace-expired-explanation>
                {{ $data['expired_explanation'] }}
            </p>
        @endif

        <x-filament::section
            :heading="__('capell-marketplace::marketplace.purchases.installed_heading')"
        >
            <div class="space-y-3">
                @forelse ($data['installed'] as $extension)
                    <article
                        class="rounded-lg border border-gray-200 p-4 dark:border-white/10"
                        data-capell-marketplace-licence
                    >
                        <p class="font-semibold text-gray-950 dark:text-white">{{ $extension['name'] }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('capell-marketplace::marketplace.purchases.status', ['status' => $extension['status']]) }} {{ __('capell-marketplace::marketplace.purchases.observed', ['date' => $extension['checked_at']?->toAtomString() ?? __('capell-marketplace::marketplace.purchases.observation_unknown')]) }}
                            @if ($extension['refresh_failed'])
                                {{ __('capell-marketplace::marketplace.purchases.licence_retained') }}
                            @endif
                        </p>
                    </article>
                @empty
                    <p>{{ __('capell-marketplace::marketplace.purchases.installed_empty') }}</p>
                @endforelse
            </div>
        </x-filament::section>

        <div class="flex gap-3">
            @if ($data['renewal_url'])
                <x-filament::button
                    tag="a"
                    :href="$data['renewal_url']"
                    target="_blank"
                >
                    {{ __('capell-marketplace::marketplace.purchases.renew') }}
                </x-filament::button>
            @endif
            @if ($data['support_url'])
                <x-filament::button
                    tag="a"
                    color="gray"
                    :href="$data['support_url']"
                    target="_blank"
                >
                    {{ __('capell-marketplace::marketplace.purchases.support') }}
                </x-filament::button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
