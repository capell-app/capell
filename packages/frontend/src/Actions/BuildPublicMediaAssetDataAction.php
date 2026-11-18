<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Frontend\Data\PublicMediaAssetData;
use Capell\Frontend\Support\View\PublicPageRelations;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicMediaAssetDataAction
{
    use AsFake;
    use AsObject;

    public function handle(object $asset, mixed $loop, string $componentItem): PublicMediaAssetData
    {
        $image = $asset instanceof MediaContract ? $asset : PublicPageRelations::image($asset);
        $component = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);

        return new PublicMediaAssetData(Blade::render(<<<'BLADE'
            <x-dynamic-component
                :component="$component"
                :$loop
                :image="$image"
                class="capell-component capell-media-asset media-asset"
            />
            BLADE, ['component' => $component, 'loop' => $loop, 'image' => $image]));
    }
}
