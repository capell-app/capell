@php
    use Filament\Support\Enums\FontWeight;
    use Filament\Support\Enums\IconSize;
@endphp

@props([
    'color' => 'gray',
    'url',
    'page',
    'resourceClass',
    'resourceIcon',
])
@php
    $ancestorLabel = $page->relationLoaded('ancestors') && $page->ancestors !== null
        ? $page->ancestors->pluck('name')->join(' &raquo; ')
        : '';
@endphp

<div class="flex flex-wrap items-center gap-x-2 py-2">
    <x-filament::link
        :href="$resourceClass::getUrl('edit', ['record' => $page])"
        :icon="$page->blueprint?->admin['icon'] ?? $resourceIcon"
        :iconSize="IconSize::Small"
        :color="$color"
    >
        {{ $ancestorLabel }}
        {{ $page->name }}
    </x-filament::link>

    <x-filament::link
        href="{{ $url }}"
        tag="a"
        target="_blank"
        size="xs"
        :weight="FontWeight::Normal"
    >
        {{ $url }}
    </x-filament::link>
</div>
