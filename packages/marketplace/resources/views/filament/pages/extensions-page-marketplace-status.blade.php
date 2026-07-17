@php
    $marketplaceConnectionState = $marketplaceConnection->connectionState();
    $marketplaceConnectionActionsVisible ??= false;
    $marketplaceConnectionButtonsVisible ??= $marketplaceConnectionActionsVisible;
    $marketplaceConnectionDetailsVisible ??= false;
    $marketplaceConnectionLabelLanguagePath = $marketplaceConnection->connectionLanguagePath('label');
    $marketplaceInstance = $marketplaceConnectionDetailsVisible ? $marketplaceConnection->instance() : null;
    $showMarketplaceConnectionActions = $marketplaceConnectionButtonsVisible
        && $marketplaceConnection->canStartRegistration()
        && $marketplaceConnectionState !== 'needs_configuration';
    $showMarketplaceAccountConnectionAction = $showMarketplaceConnectionActions
        && $marketplaceConnectionState === 'not_connected';
    $marketplaceNotificationAction = 'connectMarketplaceAccount';
    $marketplaceConnectionBody = $marketplaceConnectionActionsVisible
        ? $marketplaceConnection->connectionBody()
        : (string) __('capell-marketplace::marketplace.marketplace.status.' . $marketplaceConnectionState . '.view_only_body');
    $commercial = $marketplaceConnectionDetailsVisible && $marketplaceInstance !== null
        ? data_get($marketplaceInstance->connection_metadata, 'commercial')
        : null;
@endphp

<section
    wire:connect-marketplace.window="mountAction('{{ $marketplaceNotificationAction }}')"
    @class([
        'rounded-lg border p-4 shadow-sm',
        'border-red-300 bg-red-50 text-gray-950 dark:border-red-500/60 dark:bg-red-950/40 dark:text-gray-100' => $marketplaceConnectionState === 'needs_configuration',
        'border-yellow-300 bg-yellow-50 text-gray-950 dark:border-yellow-500/60 dark:bg-yellow-950/40 dark:text-gray-100' => $marketplaceConnectionState === 'not_connected',
        'border-gray-200 bg-white text-gray-950 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100' => $marketplaceConnectionState === 'connected',
    ])
>
    <div
        class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
    >
        <div class="min-w-0 space-y-2">
            <div
                aria-labelledby="capell-marketplace-status-title"
                aria-describedby="capell-marketplace-status-body"
                class="space-y-2"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="text-xs font-semibold tracking-wide uppercase opacity-75"
                    >
                        {{ __('capell-marketplace::marketplace.marketplace.status_badge') }}
                    </span>
                    <span
                        @class([
                            'inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-xs font-medium ring-1',
                            'bg-red-100 text-red-900 ring-red-300 dark:bg-red-900/50 dark:text-red-100 dark:ring-red-400/50' => $marketplaceConnectionState === 'needs_configuration',
                            'bg-yellow-100 text-yellow-900 ring-yellow-300 dark:bg-yellow-900/50 dark:text-yellow-100 dark:ring-yellow-400/50' => $marketplaceConnectionState === 'not_connected',
                            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-400/40' => $marketplaceConnectionState === 'connected',
                        ])
                    >
                        <span
                            class="h-1.5 w-1.5 rounded-full bg-current"
                        ></span>
                        {{ __($marketplaceConnectionLabelLanguagePath) }}
                    </span>
                </div>

                <div>
                    <h2
                        id="capell-marketplace-status-title"
                        class="text-base font-semibold"
                    >
                        {{ $marketplaceConnection->connectionTitle() }}
                    </h2>
                    <p
                        id="capell-marketplace-status-body"
                        class="mt-1 max-w-3xl text-sm leading-6 text-gray-700 dark:text-gray-300"
                    >
                        {{ $marketplaceConnectionBody }}
                    </p>
                </div>

                @if ($marketplaceConnectionDetailsVisible && $marketplaceInstance !== null)
                    <div
                        class="flex flex-wrap gap-x-3 gap-y-1 text-xs opacity-80"
                    >
                        @if ($marketplaceInstance->account_email)
                            <span>
                                {{ __('capell-marketplace::marketplace.marketplace.account_email', ['email' => $marketplaceInstance->account_email]) }}
                            </span>
                        @endif

                        <span>
                            {{ __('capell-marketplace::marketplace.marketplace.instance_id', ['id' => $marketplaceInstance->instance_id]) }}
                        </span>
                    </div>

                    @if (is_array($commercial))
                        <div
                            class="mt-3 grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm md:grid-cols-2 dark:border-white/10 dark:bg-white/5"
                        >
                            <div class="space-y-2">
                                <h3 class="font-semibold">
                                    {{ __('capell-marketplace::marketplace.marketplace.commercial.current_heading') }}
                                </h3>
                                @forelse (data_get($commercial, 'purchases', []) as $purchase)
                                    <div>
                                        <p class="font-medium">
                                            {{ data_get($purchase, 'name') }}
                                        </p>
                                        <p
                                            class="text-xs text-gray-600 dark:text-gray-300"
                                        >
                                            {{ __('capell-marketplace::marketplace.marketplace.commercial.status', ['status' => ucfirst((string) data_get($purchase, 'status'))]) }}
                                            ·
                                            {{ data_get($purchase, 'protected_updates') ? __('capell-marketplace::marketplace.marketplace.commercial.updates_included') : __('capell-marketplace::marketplace.marketplace.commercial.updates_expired') }}
                                        </p>
                                        @if (data_get($purchase, 'access_ends_at'))
                                            <p
                                                class="text-xs text-gray-600 dark:text-gray-300"
                                            >
                                                {{ __('capell-marketplace::marketplace.marketplace.commercial.access_ends', ['date' => date_create_immutable((string) data_get($purchase, 'access_ends_at'))->format('M j, Y')]) }}
                                            </p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-gray-600 dark:text-gray-300">
                                        {{ __('capell-marketplace::marketplace.marketplace.commercial.no_purchases') }}
                                    </p>
                                @endforelse

                                <p
                                    class="text-xs text-gray-600 dark:text-gray-300"
                                >
                                    {{ data_get($commercial, 'expired_explanation') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <h3 class="font-semibold">
                                    {{ data_get($commercial, 'membership_comparison.name', __('capell-marketplace::marketplace.marketplace.commercial.membership_heading')) }}
                                </h3>
                                <p>
                                    {{
                                        __('capell-marketplace::marketplace.marketplace.commercial.membership_price', [
                                            'price' => number_format(((int) data_get($commercial, 'membership_comparison.price_cents', 0)) / 100, 2),
                                            'renewal' => number_format(((int) data_get($commercial, 'membership_comparison.renewal_price_cents', 0)) / 100, 2),
                                            'currency' => data_get($commercial, 'membership_comparison.currency', 'GBP'),
                                        ])
                                    }}
                                </p>
                                <p
                                    class="text-xs text-gray-600 dark:text-gray-300"
                                >
                                    {{
                                        __('capell-marketplace::marketplace.marketplace.commercial.membership_includes', [
                                            'products' => data_get($commercial, 'membership_comparison.included_product_count', 0),
                                            'users' => data_get($commercial, 'membership_comparison.named_user_limit', 0),
                                            'new' => data_get($commercial, 'new_membership_product_count', 0),
                                        ])
                                    }}
                                </p>
                                <p
                                    class="text-xs text-gray-600 dark:text-gray-300"
                                >
                                    {{ __('capell-marketplace::marketplace.marketplace.commercial.priority_support', ['price' => number_format(((int) data_get($commercial, 'priority_support_price_cents', 0)) / 100, 2)]) }}
                                </p>
                                <div
                                    class="flex flex-wrap gap-3 text-xs font-medium"
                                >
                                    <a
                                        class="text-primary-600 dark:text-primary-400 hover:underline"
                                        href="{{ data_get($commercial, 'renewal_url') }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {{ __('capell-marketplace::marketplace.marketplace.commercial.renew') }}
                                    </a>
                                    <a
                                        class="text-primary-600 dark:text-primary-400 hover:underline"
                                        href="{{ data_get($commercial, 'support_url') }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {{ __('capell-marketplace::marketplace.marketplace.commercial.support') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            @if ($showMarketplaceAccountConnectionAction)
                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <x-filament::button
                        color="primary"
                        icon="heroicon-o-user-circle"
                        size="sm"
                        wire:click="mountAction('connectMarketplaceAccount')"
                    >
                        {{ __('capell-marketplace::marketplace.marketplace.connect_account_button') }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</section>
