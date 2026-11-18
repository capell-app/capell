@php
    use Capell\Core\Actions\ResolveRenderableComponentAction;
    use Capell\Core\Contracts\Media\MediaContract;
    use Capell\Core\Enums\AssetComponentEnum;
    use Capell\Core\Enums\RenderableTypeEnum;
@endphp

@props([
    'asset',
    'loop',
    'componentItem' => AssetComponentEnum::Card->value,
])
@php
    $image = $asset instanceof MediaContract
        ? $asset
        : (method_exists($asset, 'relationLoaded') && $asset->relationLoaded('image') ? $asset->image : (method_exists($asset, 'relationLoaded') && $asset->relationLoaded('media') ? $asset->getRelation('media')->first() : null));
    $componentItem = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);
@endphp

<x-dynamic-component
    :component="$componentItem"
    :$loop
    :image="$image"
    class="capell-component capell-media-asset media-asset"
/>
