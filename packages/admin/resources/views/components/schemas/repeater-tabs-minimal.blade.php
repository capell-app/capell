@php
    $containers = $getItems();
    $fieldWrapperView = $getFieldWrapperView();
    $isCompact = $isCompact();
    $isContained = $isContained();
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    wire:key="{{ $getLivewireKey() }}.nav"
    :attributes="
        $attributes->merge($getExtraAttributes(), escape: false)
            ->merge($getExtraAlpineAttributeBag()->getAttributes(), escape: false)->class([
            'fi-fo-repeater fi-fo-repeater-minimal',
            'fi-contained sticky top-16 flex flex-col' => $isContained,
            'p-4' => $isCompact && $isContained,
            'p-6' => ! $isCompact && $isContained,
        ])
    "
>
    <input
        type="hidden"
        value="{{ collect(array_keys($containers))->values()->toJson() }}"
        x-ref="repeaterData"
    />
    @foreach ($containers as $uuid => $item)
        <div
            id="{{ $item->getLivewireKey() }}.panel"
            role="tabpanel"
            aria-label="{{ $getItemLabel($uuid) }}"
            tabindex="0"
            wire:key="{{ $item->getLivewireKey() }}.item"
        >
            {{ $item }}
        </div>
    @endforeach
</x-dynamic-component>
