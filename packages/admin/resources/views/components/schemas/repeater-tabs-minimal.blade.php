@php
    $containers = $getChildComponentContainers();
    $fieldWrapperView = $getFieldWrapperView();
    $isCompact = $isCompact();
    $statePath = $getStatePath();
    $hasSection = $hasSection();
@endphp

<x-dynamic-component
    :component="$fieldWrapperView"
    :field="$field"
    wire:key="{{ $this->getId() }}.{{ $statePath }}.{{ $field::class }}.nav"
    {{
    $attributes->merge($getExtraAttributes(), escape: false)->class([
        'fi-fo-repeater fi-fo-repeater-minimal',
        'fi-contained sticky top-16 flex flex-col' => $hasSection,
        'p-4' => $isCompact && $hasSection,
        'p-6' => ! $isCompact && $hasSection,
    ])
}}
    {{ $getExtraAlpineAttributeBag() }}
>
    <input
        type="hidden"
        value="{{ collect(array_keys($containers))->values()->toJson() }}"
        x-ref="repeaterData"
    />
    @foreach ($containers as $uuid => $item)
        <div
            id="{{ $this->getId() . '-' . $item->getStatePath() }}"
            role="tabpanel"
            aria-labelledby="{{ $uuid }}"
            tabindex="0"
            wire:key="{{ $this->getId() }}.{{ $item->getStatePath() }}.{{ $field::class }}.item"
        >
            {{ $item }}
        </div>
    @endforeach
</x-dynamic-component>
