<x-filament-panels::page>
    @php
        $data = $this->purchasesData();
        $snapshotState = $this->refreshFailed ? 'retained' : $data['snapshot_state'];
        $snapshot = match ($snapshotState) {
            'retained' => ['color' => 'warning', 'icon' => 'heroicon-o-exclamation-triangle', 'text' => __('capell-marketplace::marketplace.purchases.snapshot.retained')],
            'not_connected' => ['color' => 'gray', 'icon' => 'heroicon-o-link-slash', 'text' => __('capell-marketplace::marketplace.purchases.snapshot.not_connected')],
            'not_observed' => ['color' => 'info', 'icon' => 'heroicon-o-clock', 'text' => __('capell-marketplace::marketplace.purchases.snapshot.not_observed')],
            default => ['color' => 'success', 'icon' => 'heroicon-o-check-circle', 'text' => __('capell-marketplace::marketplace.purchases.snapshot.current')],
        };
        $tone = [
            'warning' => 'bg-warning-50 text-warning-900 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-100 dark:ring-warning-400/20',
            'gray' => 'bg-white text-gray-700 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10',
            'info' => 'bg-info-50 text-info-900 ring-info-600/20 dark:bg-info-500/10 dark:text-info-100 dark:ring-info-400/20',
            'success' => 'bg-success-50 text-success-900 ring-success-600/20 dark:bg-success-500/10 dark:text-success-100 dark:ring-success-400/20',
        ][$snapshot['color']];
        $panel = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
        $statusColor = fn (?string $status): string => match ($status) {
            'active', 'purchased' => 'success',
            'expired', 'revoked' => 'danger',
            'domain_mismatch', 'unverified' => 'warning',
            default => 'gray',
        };
        $hasAccountLinks = $data['renewal_url'] || $data['support_url'] || $data['priority_support_price'] !== null || $data['expired_explanation'] !== null;
    @endphp

    <div
        class="space-y-6"
        data-capell-marketplace-purchases
    >
        <div
            data-capell-account-snapshot="{{ $snapshotState }}"
            role="status"
            class="{{ $tone }} flex items-start gap-3 rounded-xl p-4 text-sm ring-1"
        >
            <x-filament::icon
                :icon="$snapshot['icon']"
                class="mt-0.5 size-5 shrink-0"
            />
            <div class="space-y-0.5">
                <p class="font-medium">{{ $snapshot['text'] }}</p>
                @if ($data['observed_at'])
                    <p class="opacity-80">{{ __('capell-marketplace::marketplace.purchases.observed', ['date' => $data['observed_at']]) }}</p>
                @endif
            </div>
        </div>

        <div
            class="{{ $hasAccountLinks ? 'lg:grid-cols-[minmax(0,1fr)_20rem]' : '' }} grid gap-6 lg:items-start"
        >
            <div class="min-w-0 space-y-6">
                <section
                    class="{{ $panel }}"
                    aria-labelledby="marketplace-account-purchases-heading"
                >
                    <header
                        class="border-b border-gray-950/5 px-5 py-4 dark:border-white/10"
                    >
                        <h2
                            id="marketplace-account-purchases-heading"
                            class="text-base font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.purchases.account_heading') }}
                        </h2>
                    </header>

                    @if ($data['purchases'] !== [])
                        <ul
                            class="divide-y divide-gray-950/5 dark:divide-white/10"
                        >
                            @foreach ($data['purchases'] as $purchase)
                                <li
                                    class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                                    data-capell-marketplace-purchase
                                >
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ data_get($purchase, 'name') }}</p>
                                        @if (data_get($purchase, 'access_ends_at'))
                                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                                {{ __('capell-marketplace::marketplace.purchases.access_ends', ['date' => \Carbon\CarbonImmutable::parse((string) data_get($purchase, 'access_ends_at'))->translatedFormat('j M Y')]) }}
                                            </p>
                                        @endif
                                    </div>
                                    <x-filament::badge
                                        :color="$statusColor((string) data_get($purchase, 'status', 'unknown'))"
                                        class="self-start sm:self-auto"
                                    >
                                        {{ __('capell-marketplace::marketplace.purchases.status', ['status' => data_get($purchase, 'status', 'unknown')]) }}
                                    </x-filament::badge>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div
                            class="flex flex-col items-center gap-3 px-5 py-10 text-center"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400"
                            >
                                <x-filament::icon
                                    :icon="$data['snapshot_state'] === 'current' ? 'heroicon-o-shopping-bag' : $snapshot['icon']"
                                    class="size-6"
                                />
                            </span>
                            <p class="max-w-md text-sm text-gray-600 dark:text-gray-300" @if ($data['snapshot_state'] === 'current') data-capell-marketplace-purchases-empty @endif>
                                {{ $data['snapshot_state'] === 'current' ? __('capell-marketplace::marketplace.purchases.empty') : __('capell-marketplace::marketplace.purchases.account_unavailable') }}
                            </p>
                        </div>
                    @endif
                </section>

                <section
                    class="{{ $panel }}"
                    aria-labelledby="marketplace-installed-paid-heading"
                >
                    <header
                        class="border-b border-gray-950/5 px-5 py-4 dark:border-white/10"
                    >
                        <h2
                            id="marketplace-installed-paid-heading"
                            class="text-base font-semibold text-gray-950 dark:text-white"
                        >
                            {{ __('capell-marketplace::marketplace.purchases.installed_heading') }}
                        </h2>
                    </header>

                    @if ($data['installed'] !== [])
                        <ul
                            class="divide-y divide-gray-950/5 dark:divide-white/10"
                        >
                            @foreach ($data['installed'] as $extension)
                                <li
                                    class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
                                    data-capell-marketplace-licence
                                >
                                    <div class="min-w-0 space-y-0.5">
                                        <p class="font-medium text-gray-950 dark:text-white">{{ $extension['name'] }}</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ __('capell-marketplace::marketplace.purchases.observed', ['date' => $extension['checked_at']?->toAtomString() ?? __('capell-marketplace::marketplace.purchases.observation_unknown')]) }}
                                        </p>
                                        @if ($extension['refresh_failed'])
                                            <p class="text-sm text-warning-700 dark:text-warning-300">{{ __('capell-marketplace::marketplace.purchases.licence_retained') }}</p>
                                        @endif
                                    </div>
                                    <x-filament::badge
                                        :color="$statusColor($extension['status'])"
                                        class="self-start"
                                    >
                                        {{ __('capell-marketplace::marketplace.purchases.status', ['status' => $extension['status']]) }}
                                    </x-filament::badge>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div
                            class="flex flex-col items-center gap-3 px-5 py-10 text-center"
                        >
                            <span
                                class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400"
                            >
                                <x-filament::icon
                                    icon="heroicon-o-puzzle-piece"
                                    class="size-6"
                                />
                            </span>
                            <p class="max-w-md text-sm text-gray-600 dark:text-gray-300">{{ __('capell-marketplace::marketplace.purchases.installed_empty') }}</p>
                        </div>
                    @endif
                </section>
            </div>

            @if ($hasAccountLinks)
                <aside class="{{ $panel }} space-y-4 p-5 lg:sticky lg:top-20">
                    <h2
                        class="text-base font-semibold text-gray-950 dark:text-white"
                    >
                        {{ __('capell-marketplace::marketplace.purchases.account_panel_heading') }}
                    </h2>

                    @if ($data['expired_explanation'] !== null)
                        <p class="text-sm text-warning-800 dark:text-warning-200" data-capell-marketplace-expired-explanation>{{ $data['expired_explanation'] }}</p>
                    @endif

                    @if ($data['priority_support_price'] !== null)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ __('capell-marketplace::marketplace.purchases.priority_support', ['price' => $data['priority_support_price']]) }}
                        </p>
                    @endif

                    <div class="flex flex-col gap-2">
                        @if ($data['renewal_url'])
                            <x-filament::button
                                tag="a"
                                :href="$data['renewal_url']"
                                target="_blank"
                                rel="noopener noreferrer"
                                icon="heroicon-o-arrow-path"
                            >
                                {{ __('capell-marketplace::marketplace.purchases.renew') }}
                            </x-filament::button>
                        @endif
                        @if ($data['support_url'])
                            <x-filament::button
                                tag="a"
                                color="gray"
                                outlined
                                :href="$data['support_url']"
                                target="_blank"
                                rel="noopener noreferrer"
                                icon="heroicon-o-lifebuoy"
                            >
                                {{ __('capell-marketplace::marketplace.purchases.support') }}
                            </x-filament::button>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
    </div>
</x-filament-panels::page>
