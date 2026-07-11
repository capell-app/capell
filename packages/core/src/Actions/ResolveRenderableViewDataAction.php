<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(RenderableDefinitionData $definition, Model $asset, Model $translation, array<string, mixed> $meta = [], array<string, mixed> $dynamicData = [], ?string $renderKey = null)
 */
final class ResolveRenderableViewDataAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $dynamicData
     * @return array<string, mixed>
     */
    public function handle(
        RenderableDefinitionData $definition,
        Model $asset,
        Model $translation,
        array $meta = [],
        array $dynamicData = [],
        ?string $renderKey = null,
    ): array {
        $renderKey ??= $definition->key;

        $context = new RenderableViewDataContext(
            asset: $asset,
            translation: $translation,
            meta: $meta,
            dynamicData: $dynamicData,
            renderKey: $renderKey,
        );

        $canonical = [
            'asset' => $asset,
            'translation' => $translation,
            'meta' => $meta,
            'dynamicData' => $dynamicData,
            'renderKey' => $renderKey,
        ];

        return array_replace($definition->resolveViewData($context), $canonical);
    }
}
