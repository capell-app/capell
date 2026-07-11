@props([
    'type',
    'key',
    'asset',
    'translation',
    'meta' => [],
    'dynamicData' => [],
    'implementation' => 'blade',
])

@php
    use Capell\Frontend\Actions\RenderRenderableAction;
@endphp

{!! RenderRenderableAction::run($type, $key, $asset, $translation, (array) $meta, (array) $dynamicData, (string) $implementation) !!}
