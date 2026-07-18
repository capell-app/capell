<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Support\Renderables\RenderableRegistry;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(RenderableTypeEnum|string $type, string $key, string $implementation = 'blade')
 */
final class ResolveRenderableComponentAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RenderableRegistry $registry,
    ) {}

    public function handle(RenderableTypeEnum|string $type, string $key, string $implementation = 'blade'): string
    {
        $definition = $this->registry->get($type, $key);

        $component = match ($implementation) {
            'adminPreview' => $definition->adminPreview,
            'assetComponent' => $definition->assetComponent,
            'blade' => $definition->blade,
            'component' => $definition->assetComponent,
            'livewire' => $definition->livewire,
            default => throw new InvalidArgumentException(sprintf('Renderable implementation [%s] is not supported.', $implementation)),
        };

        throw_if(
            $component === null || $component === '',
            InvalidArgumentException::class,
            sprintf('Renderable [%s] does not define [%s].', $definition->key, $implementation),
        );

        return $component;
    }
}
