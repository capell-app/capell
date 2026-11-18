@props([
    'asset',
    'loop',
    'componentItem' => \Capell\Core\Enums\AssetComponentEnum::Card->value,
])

@includeIsolated('capell::public.media-asset', [
    'attributes' => new \Illuminate\View\ComponentAttributeBag,
    'renderData' => app(\Capell\Frontend\Data\PublicMediaAssetData::class, [
        'asset' => $asset,
        'loop' => $loop,
        'componentItem' => $componentItem,
    ]),
])
