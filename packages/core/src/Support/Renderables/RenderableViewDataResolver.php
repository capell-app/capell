<?php

declare(strict_types=1);

namespace Capell\Core\Support\Renderables;

interface RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    public function data(RenderableViewDataContext $context): array;
}
