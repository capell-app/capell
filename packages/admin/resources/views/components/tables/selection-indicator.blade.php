@php
    use Filament\Support\Facades\FilamentView;
    use Filament\Tables\View\TablesRenderHook;
    use Illuminate\Support\Number;
    use Illuminate\View\ComponentAttributeBag;
@endphp

@props([
    'allSelectableRecordsCount',
    'page',
    'isSelectionDisabled' => false,
    'selectAllRecordsAction' => 'selectAllRecords',
    'deselectAllRecordsAction' => 'deselectAllRecords',
    'getSelectedRecordsCountAction' => 'getSelectedRecordsCount()',
])

<div
    x-cloak
    x-bind:hidden="! {{ $getSelectedRecordsCountAction }}"
    x-show="{{ $getSelectedRecordsCountAction }}"
    wire:key="{{ $this->getId() }}.table.selection.indicator"
    {{ $attributes->merge(['class' => 'fi-ta-selection-indicator']) }}
>
    <div class="flex items-center gap-x-1">
        {{
            \Filament\Support\generate_loading_indicator_html(new ComponentAttributeBag([
                'x-show' => 'isLoading',
            ]))
        }}

        <span
            x-text="
                window.pluralize(@js(__('filament-tables::table.selection_indicator.selected_count')), {{ $getSelectedRecordsCountAction }}, {
                    count: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale()))).format(
                        {{ $getSelectedRecordsCountAction }},
                    ),
                })
            "
        ></span>
    </div>

    @if (! $isSelectionDisabled)
        <div>
            {{ FilamentView::renderHook(TablesRenderHook::SELECTION_INDICATOR_ACTIONS_BEFORE, scopes: static::class) }}

            <div class="fi-ta-selection-indicator-actions-ctn">
                <x-filament::link
                    color="primary"
                    tag="button"
                    x-on:click="{{ $selectAllRecordsAction }}"
                    {{-- Make sure the Alpine attributes get re-evaluated after a Livewire request: --}}
                    :wire:key="$this->getId() . 'table.selection.indicator.actions.select-all.' . $allSelectableRecordsCount . '.' . $page"
                >
                    {{ trans_choice('filament-tables::table.selection_indicator.actions.select_all.label', $allSelectableRecordsCount, ['count' => Number::format($allSelectableRecordsCount, locale: app()->getLocale())]) }}
                </x-filament::link>

                <x-filament::link
                    color="danger"
                    tag="button"
                    x-on:click="{{ $deselectAllRecordsAction }}"
                >
                    {{ __('filament-tables::table.selection_indicator.actions.deselect_all.label') }}
                </x-filament::link>
            </div>

            {{ FilamentView::renderHook(TablesRenderHook::SELECTION_INDICATOR_ACTIONS_AFTER, scopes: static::class) }}
        </div>
    @endif
</div>
