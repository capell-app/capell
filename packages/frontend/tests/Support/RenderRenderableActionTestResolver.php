<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Override;

final class RenderRenderableActionTestResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'headline' => 'Resolver headline',
        ];
    }
}
