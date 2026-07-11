<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Spatie\LaravelData\Data;

class RenderableDefinitionData extends Data
{
    /**
     * @param  class-string<RenderableViewDataResolver>|null  $viewDataResolver
     */
    public function __construct(
        public readonly string $key,
        public readonly RenderableTypeEnum|string $type,
        public readonly ?string $blade = null,
        public readonly ?string $livewire = null,
        public readonly ?string $adminPreview = null,
        public readonly ?string $assetComponent = null,
        public readonly ?string $viewDataResolver = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolveViewData(RenderableViewDataContext $context): array
    {
        if ($this->viewDataResolver === null) {
            return [];
        }

        $resolver = resolve($this->viewDataResolver);

        return $resolver instanceof RenderableViewDataResolver
            ? $resolver->data($context)
            : [];
    }
}
