@php
    /** @var array{kind: array<string, string>, category: array<string, string>, sort: array<string, string>} $filterOptions */
@endphp

<div
    class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
>
    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
        <label class="min-w-0 flex-1">
            <span class="sr-only">
                {{ __('capell-marketplace::marketplace.filters.search_placeholder') }}
            </span>
            <input
                type="search"
                wire:model.live.debounce.300ms="tableSearch"
                placeholder="{{ __('capell-marketplace::marketplace.filters.search_placeholder') }}"
                class="w-full rounded-lg border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-950 shadow-sm transition placeholder:text-gray-400 focus:border-blue-600 focus:ring-blue-600 dark:border-white/10 dark:bg-gray-950 dark:text-white"
            />
        </label>

        <div class="flex flex-wrap gap-2">
            @if ($this->lockedKind === null)
                <select
                    wire:model.live="tableFilters.kind.value"
                    class="rounded-md border-gray-200 bg-white py-2 text-sm text-gray-700 shadow-sm focus:border-blue-600 focus:ring-blue-600 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200"
                >
                    <option value="">
                        {{ __('capell-marketplace::marketplace.filters.all_types') }}
                    </option>
                    @foreach ($filterOptions['kind'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif

            <select
                wire:model.live="tableFilters.category.value"
                class="rounded-md border-gray-200 bg-white py-2 text-sm text-gray-700 shadow-sm focus:border-blue-600 focus:ring-blue-600 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200"
            >
                <option value="">
                    {{ __('capell-marketplace::marketplace.filters.all_categories') }}
                </option>
                @foreach ($filterOptions['category'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="tableFilters.sort.value"
                class="rounded-md border-gray-200 bg-white py-2 text-sm text-gray-700 shadow-sm focus:border-blue-600 focus:ring-blue-600 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200"
            >
                @foreach ($filterOptions['sort'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        @foreach (['recommended', 'free', 'themes'] as $preset)
            <button
                type="button"
                wire:click="applyMarketplacePreset('{{ $preset }}')"
                class="rounded-md bg-gray-50 px-2.5 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-gray-200 transition hover:bg-gray-100 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10"
            >
                {{ __('capell-marketplace::marketplace.filters.presets.' . $preset) }}
            </button>
        @endforeach
    </div>
</div>
