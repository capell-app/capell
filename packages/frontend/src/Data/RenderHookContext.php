<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

class RenderHookContext
{
    public function __construct(
        public readonly string $location,
        public readonly mixed $item,
    ) {}
}
