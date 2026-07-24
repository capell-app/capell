<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Actions\ResolveRenderableViewDataAction;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
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

        $this->recordManifestContribution($asset, $key, (microtime(true) - $startedAt) * 1000);

        return $rendered;
    }

    private function recordManifestContribution(Model $asset, string $key, float $elapsedMilliseconds): void
    {
        foreach (resolve(CapellPackageRegistry::class)->all() as $manifest) {
            foreach ($manifest->contributes as $contribution) {
                if (! in_array($contribution->type, [
                    ExtensionContributionType::Section,
                    ExtensionContributionType::Asset,
                ], true) || ! $this->matchesRenderable($manifest, $contribution->metadata, $asset, $key)) {
                    continue;
                }

                RecordManifestRenderContributionAction::run(
                    packageName: $manifest->name,
                    contributionType: $contribution->type->value,
                    contributionClass: $contribution->class,
                    elapsedMilliseconds: $elapsedMilliseconds,
                    cacheSafe: $manifest->performance->cacheSafety->cacheable,
                );
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function matchesRenderable(
        CapellManifestData $manifest,
        array $metadata,
        Model $asset,
        string $key,
    ): bool {
        $surface = $metadata['surface'] ?? null;

        if ((is_string($surface) && $surface !== 'frontend')
            || (! is_string($surface) && ! in_array('frontend', $manifest->surfaces, true))) {
            return false;
        }

        $modelClass = $metadata['modelClass'] ?? null;
        $declaredKey = $metadata['section'] ?? $metadata['asset'] ?? $metadata['key'] ?? null;

        if (is_string($modelClass) && $modelClass !== '' && ! $asset instanceof $modelClass) {
            return false;
        }

        if (is_string($declaredKey) && $declaredKey !== '' && $declaredKey !== $key) {
            return false;
        }

        return (is_string($modelClass) && $modelClass !== '')
            || (is_string($declaredKey) && $declaredKey !== '');
    }
}
