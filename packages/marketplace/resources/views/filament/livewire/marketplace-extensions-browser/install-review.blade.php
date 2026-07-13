@php
    /** @var array<string, mixed> $selection */
    $dependencyCount = count($selection['dependency_records']);
@endphp

<div
    class="space-y-5"
    x-init="$nextTick(() => $el.querySelector('[data-marketplace-review-heading]')?.focus())"
>
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
    >
        <div class="space-y-1">
            <h3
                tabindex="-1"
                data-marketplace-review-heading
                class="text-base font-semibold text-gray-950 outline-none dark:text-white"
            >
                {{ __('capell-marketplace::marketplace.selection.review_heading') }}
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{
                    trans_choice('capell-marketplace::marketplace.selection.review_summary', $selection['selected_count'], [
                        'count' => $selection['selected_count'],
                    ])
                }}
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('capell-marketplace::marketplace.selection.review_description') }}
            </p>
        </div>
    </div>

    <div
        class="rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/30"
    >
        {{ __('capell-marketplace::marketplace.selection.review_not_started_notice') }}
    </div>

    @if ($selection['has_premium_records'])
        <div
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30"
        >
            {{ __('capell-marketplace::marketplace.selection.premium_notice') }}
        </div>
    @endif

    @if ($selection['missing_dependencies'] !== [] || $selection['blocked_dependencies'] !== [])
        <div
            class="space-y-2 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30"
        >
            @if ($selection['missing_dependencies'] !== [])
                <p>
                    {{ __('capell-marketplace::marketplace.selection.missing_dependencies', ['dependencies' => implode(', ', $selection['missing_dependencies'])]) }}
                </p>
            @endif

            @foreach ($selection['blocked_dependencies'] as $dependency)
                <p>
                    {{
                        __('capell-marketplace::marketplace.selection.blocked_dependency', [
                            'name' => $dependency['name'],
                            'reason' => $dependency['reason'] ?? __('capell-marketplace::marketplace.selection.blocked.unavailable'),
                        ])
                    }}
                </p>
            @endforeach
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div
            class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900"
        >
            <div
                class="border-b border-gray-200 px-4 py-3 dark:border-white/10"
            >
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('capell-marketplace::marketplace.selection.selected_extensions_heading') }}
                </h4>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($selection['explicit_records'] as $record)
                    @php
                        $composerName = is_string($record['composer_name'] ?? null) ? $record['composer_name'] : '';
                        $isPremium = in_array($record, $selection['premium_records'], true);
                    @endphp

                    <div
                        class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p
                                    class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $record['name'] ?? $composerName }}
                                </p>

                                @if ($isPremium)
                                    <span
                                        class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        {{ __('capell-marketplace::marketplace.selection.premium_badge') }}
                                    </span>
                                @endif

                                @if (($record['maturity'] ?? null) === 'beta')
                                    <span
                                        class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        {{ __('capell-marketplace::marketplace.release_status.beta') }}
                                    </span>
                                @endif
                            </div>

                            @if ($composerName !== '')
                                <p
                                    class="truncate text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ $composerName }}
                                </p>
                            @endif

                            @if (is_array($record['install_confirmation'] ?? null) && is_string($record['install_confirmation']['summary'] ?? null))
                                <p
                                    class="text-sm text-gray-600 dark:text-gray-400"
                                >
                                    {{ $record['install_confirmation']['summary'] }}
                                </p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div
            class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="space-y-1">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('capell-marketplace::marketplace.selection.dependencies_heading') }}
                </h4>
                @if ($dependencyCount > 0)
                    <p
                        class="text-sm font-medium text-sky-700 dark:text-sky-300"
                    >
                        {{ trans_choice('capell-marketplace::marketplace.selection.dependency_count', $dependencyCount, ['count' => $dependencyCount]) }}
                    </p>
                @endif

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-marketplace::marketplace.selection.dependencies_description') }}
                </p>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($selection['dependency_records'] as $record)
                    @php
                        $composerName = is_string($record['composer_name'] ?? null) ? $record['composer_name'] : '';
                        $isPremium = in_array($record, $selection['premium_records'], true);
                    @endphp

                    <div
                        class="rounded-lg bg-sky-50 px-3 py-3 text-sm ring-1 ring-sky-600/20 dark:bg-sky-500/10 dark:ring-sky-500/30"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p
                                    class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $record['name'] ?? $composerName }}
                                </p>

                                <span
                                    class="rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-700 ring-1 ring-sky-600/20 dark:bg-sky-500/15 dark:text-sky-200"
                                >
                                    {{ __('capell-marketplace::marketplace.selection.dependency_badge') }}
                                </span>

                                @if ($isPremium)
                                    <span
                                        class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300"
                                    >
                                        {{ __('capell-marketplace::marketplace.selection.premium_badge') }}
                                    </span>
                                @endif
                            </div>

                            @if ($composerName !== '')
                                <p
                                    class="truncate text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ $composerName }}
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-marketplace::marketplace.selection.dependencies_empty') }}
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    @php
        $installOptions = collect($selection['install_records'])
            ->flatMap(fn (array $record): array => is_array($record['install_options'] ?? null) ? $record['install_options'] : [])
            ->filter(fn (mixed $option): bool => is_array($option) && is_string($option['key'] ?? null) && $option['key'] !== '')
            ->unique('key')
            ->values();
    @endphp

    @if ($installOptions->isNotEmpty())
        <div
            class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="space-y-1">
                <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ __('capell-marketplace::marketplace.selection.install_options_heading') }}
                </h4>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-marketplace::marketplace.selection.install_options_description') }}
                </p>
            </div>

            <div class="space-y-3">
                @foreach ($installOptions as $option)
                    @php
                        $optionKey = $option['key'];
                        $optionType = is_string($option['type'] ?? null) ? $option['type'] : 'checkbox';
                        $optionLabel = is_string($option['label'] ?? null) ? $option['label'] : $optionKey;
                        $optionHelp = is_string($option['help'] ?? null) ? $option['help'] : null;
                    @endphp

                    @if (in_array($optionType, ['checkbox', 'toggle', 'boolean'], true))
                        <label class="flex items-start gap-3 text-sm">
                            <x-filament::input.checkbox
                                wire:model="selectedMarketplaceInstallOptions.{{ $optionKey }}"
                            />

                            <span class="space-y-1">
                                <span
                                    class="block font-medium text-gray-900 dark:text-white"
                                >
                                    {{ $optionLabel }}
                                </span>

                                @if ($optionHelp !== null)
                                    <span
                                        class="block text-gray-600 dark:text-gray-400"
                                    >
                                        {{ $optionHelp }}
                                    </span>
                                @endif
                            </span>
                        </label>
                    @else
                        <label class="block space-y-1 text-sm">
                            <span
                                class="font-medium text-gray-900 dark:text-white"
                            >
                                {{ $optionLabel }}
                            </span>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    type="text"
                                    wire:model="selectedMarketplaceInstallOptions.{{ $optionKey }}"
                                />
                            </x-filament::input.wrapper>

                            @if ($optionHelp !== null)
                                <span
                                    class="block text-gray-600 dark:text-gray-400"
                                >
                                    {{ $optionHelp }}
                                </span>
                            @endif
                        </label>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    @if ($selection['contains_beta'])
        <label
            class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
        >
            <x-filament::input.checkbox
                wire:model.live="betaMarketplaceExtensionsAcknowledged"
            />

            <span class="space-y-1">
                <span
                    class="block font-semibold text-amber-950 dark:text-amber-100"
                >
                    {{ __('capell-marketplace::marketplace.selection.beta_acknowledgement_label') }}
                </span>
                <span class="block text-amber-800 dark:text-amber-200">
                    {{ __('capell-marketplace::marketplace.selection.beta_acknowledgement_help') }}
                </span>
            </span>
        </label>
    @endif

    <label
        class="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm dark:border-blue-500/30 dark:bg-blue-500/10"
    >
        <x-filament::input.checkbox
            wire:model.live="installReviewedMarketplaceExtensionsConfirmed"
        />

        <span class="space-y-1">
            <span class="block font-semibold text-blue-950 dark:text-blue-100">
                {{ __('capell-marketplace::marketplace.selection.confirm_download_install_label') }}
            </span>
            <span class="block text-blue-800 dark:text-blue-200">
                {{ __('capell-marketplace::marketplace.selection.confirm_download_install_help') }}
            </span>
        </span>
    </label>

    <template x-teleport="#capell-marketplace-browser-modal-footer">
        <div
            class="relative z-50 flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
        >
            <button
                type="button"
                wire:click="backToMarketplaceTable"
                class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/10"
            >
                {{ __('capell-marketplace::marketplace.selection.back_to_table') }}
            </button>

            <button
                type="button"
                wire:click="installReviewedMarketplaceExtensions"
                wire:loading.attr="disabled"
                wire:target="installReviewedMarketplaceExtensions"
                @disabled(! $selection['can_install'] || ! $this->installReviewedMarketplaceExtensionsConfirmed || ($selection['contains_beta'] && ! $this->betaMarketplaceExtensionsAcknowledged))
                @class([
                    'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold transition',
                    'bg-blue-600 text-white hover:bg-blue-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:bg-blue-500 dark:hover:bg-blue-400' => $selection['can_install'] && $this->installReviewedMarketplaceExtensionsConfirmed && (! $selection['contains_beta'] || $this->betaMarketplaceExtensionsAcknowledged),
                    'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-white/10 dark:text-gray-500' => ! $selection['can_install'] || ! $this->installReviewedMarketplaceExtensionsConfirmed || ($selection['contains_beta'] && ! $this->betaMarketplaceExtensionsAcknowledged),
                ])
            >
                <span
                    wire:loading.remove
                    wire:target="installReviewedMarketplaceExtensions"
                >
                    {{ trans_choice('capell-marketplace::marketplace.selection.final_install_count_button', $selection['install_count'], ['count' => $selection['install_count']]) }}
                </span>

                <span
                    wire:loading.flex
                    wire:target="installReviewedMarketplaceExtensions"
                    class="hidden items-center gap-2"
                >
                    <x-filament::loading-indicator class="h-4 w-4" />
                    {{ __('capell-marketplace::marketplace.selection.installing_button') }}
                </span>
            </button>
        </div>
    </template>
</div>
