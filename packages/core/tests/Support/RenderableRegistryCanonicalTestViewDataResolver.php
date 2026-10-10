<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Override;

final class RenderableRegistryCanonicalTestViewDataResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'asset' => 'overridden asset',
            'translation' => 'overridden translation',
            'meta' => ['overridden' => true],
            'dynamicData' => ['overridden' => true],
            'renderKey' => 'overridden',
            'headline' => $context->meta['headline'],
        ];
    }
}
