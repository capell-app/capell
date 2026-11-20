<?php

declare(strict_types=1);

namespace Capell\Core\Data\Rendering;

/** Extensible for existing Frontend context subclasses, which retain this type through the compatibility alias. */
class RenderHookContext
{
    public function __construct(
        public readonly string $location,
        public readonly mixed $item,
    ) {}
}
