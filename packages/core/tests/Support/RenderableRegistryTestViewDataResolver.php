<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Override;

final class RenderableRegistryTestViewDataResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'renderKey' => $context->renderKey,
            'headline' => $context->meta['headline'],
        ];
    }
}
