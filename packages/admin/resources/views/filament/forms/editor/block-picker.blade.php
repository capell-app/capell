@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\Enums\GridDirection;
    use Filament\Support\View\ComponentAttributeBag as FilamentComponentAttributeBag;
    use Illuminate\Support\Js;
@endphp

@props([
    'trigger',
    'actionAlignment' => null,
    'width' => null,
    'categories' => [],
    'allHaystacks' => [],
])

{{--
    Capell's searchable, categorised block picker. Replaces Filament's plain
    dropdown list of block labels (CAP-0300) while keeping the same
    `wire:click` add-block action, dropdown chrome, and closing behaviour.

    Search and category filtering are Alpine-only (no round trip): every item
    carries its own pre-lowercased search haystack, and `matches()` is shared
    from the root scope down through each nested category/item `x-data`.
--}}
<x-filament::dropdown
    :placement="
        match ($actionAlignment) {
            Alignment::Start, Alignment::Left => 'bottom-start',
            Alignment::End, Alignment::Right => 'bottom-end',
            default => null,
        }
    "
    shift
    :width="$width"
    :attributes="
        \Filament\Support\prepare_inherited_attributes(
            $attributes->class([
                'fi-fo-builder-block-picker',
                'fi-capell-block-picker',
                ($actionAlignment instanceof Alignment) ? ('fi-align-' . $actionAlignment->value) : $actionAlignment,
            ]),
        )
    "
>
    <x-slot name="trigger">
        {{ $trigger }}
    </x-slot>

    <div
        x-data="{
            query: '',
            matches(haystack) {
                const needle = this.query.trim().toLowerCase();

                return needle === '' || haystack.includes(needle);
            },
        }"
        class="fi-capell-block-picker-content"
    >
        <div class="p-2">
            <div class="fi-input-wrp flex items-center">
                <x-filament::icon
                    icon="heroicon-o-magnifying-glass"
                    class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline ms-3 h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                />

                <input
                    type="search"
                    x-model.debounce.100ms="query"
                    x-on:keydown.escape.stop="query = ''"
                    autocomplete="off"
                    class="fi-input block w-full appearance-none border-none bg-transparent px-3 py-1.5 text-sm text-gray-950 focus:ring-0 focus:outline-none dark:text-white"
                    placeholder="{{ __('capell-admin::form.block_picker_search_placeholder') }}"
                    aria-label="{{ __('capell-admin::form.block_picker_search_label') }}"
                />
            </div>
        </div>

        <div
            {{ (new FilamentComponentAttributeBag)->class(['fi-dropdown-list', 'max-h-80 overflow-y-auto']) }}
        >
            @foreach ($categories as $category => $items)
                <div
                    x-data="{ terms: @js(array_map(fn ($item) => $item->searchHaystack, $items)) }"
                    x-show="terms.some((term) => matches(term))"
                >
                    <x-filament::dropdown.header>
                        {{ $category }}
                    </x-filament::dropdown.header>

                    <div
                        {{ (new FilamentComponentAttributeBag)->grid(['default' => 1], GridDirection::Column) }}
                    >
                        @foreach ($items as $item)
                            <x-filament::dropdown.list.item
                                :icon="$item->icon"
                                :x-show="'matches(' . Js::from($item->searchHaystack) . ')'"
                                x-on:click="close"
                                :wire:click="$item->wireClickAction"
                            >
                                <span
                                    class="fi-capell-block-picker-item-label block font-medium"
                                >
                                    {{ $item->label }}
                                </span>

                                @if (filled($item->description))
                                    <span
                                        class="fi-capell-block-picker-item-description block text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        {{ $item->description }}
                                    </span>
                                @endif
                            </x-filament::dropdown.list.item>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div
            x-show="! ({{ Illuminate\Support\Js::from($allHaystacks) }}).some((term) => matches(term))"
            x-cloak
            class="fi-capell-block-picker-empty flex flex-col items-start gap-y-2 px-4 py-6 text-sm text-gray-500 dark:text-gray-400"
        >
            <p>{{ __('capell-admin::form.block_picker_empty_results') }}</p>

            <button
                type="button"
                x-on:click="query = ''"
                class="fi-link fi-size-sm text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ __('capell-admin::form.block_picker_reset_search') }}
            </button>
        </div>
    </div>
</x-filament::dropdown>
