<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Actions\ResolveRenderableViewDataAction;
use Capell\Core\Data\RenderableContributionIdentityData;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Frontend\Actions\Performance\RecordManifestRenderContributionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(RenderableTypeEnum|string $type, string $key, Model $asset, Model $translation, array $meta = [], array $dynamicData = [], string $implementation = 'blade')
 */
final class RenderRenderableAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RenderableRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $dynamicData
     */
    public function handle(
        RenderableTypeEnum|string $type,
        string $key,
        Model $asset,
        Model $translation,
        array $meta = [],
        array $dynamicData = [],
        string $implementation = 'blade',
    ): string {
        $startedAt = microtime(true);
        $definition = $this->registry->get($type, $key);
        $target = ResolveRenderableComponentAction::run($type, $key, $implementation);
        $viewData = ResolveRenderableViewDataAction::run($definition, $asset, $translation, $meta, $dynamicData, $key);

        $rendered = match ($implementation) {
            'blade' => view($target, $viewData)->render(),
            'assetComponent', 'component' => Blade::render(
                '<x-dynamic-component :component="$target" :asset="$asset" :translation="$translation" :meta="$meta" :dynamic-data="$dynamicData" :render-key="$renderKey" />',
                ['target' => $target, ...$viewData],
            ),
            'livewire' => Blade::render('@livewire($target, $viewData)', [
                'target' => $target,
                'viewData' => $viewData,
            ]),
            default => throw new InvalidArgumentException(sprintf('Renderable implementation [%s] is not supported.', $implementation)),
        };

        if ($definition->contribution instanceof RenderableContributionIdentityData) {
            RecordManifestRenderContributionAction::run(
                packageName: $definition->contribution->owner,
                contributionType: $definition->contribution->type->value,
                contributionClass: $definition->contribution->class,
                elapsedMilliseconds: (microtime(true) - $startedAt) * 1000,
                cacheSafe: $definition->contribution->cacheSafe,
            );
        }

        return $rendered;
    }
}
