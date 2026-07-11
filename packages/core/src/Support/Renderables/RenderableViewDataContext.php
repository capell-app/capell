<?php

declare(strict_types=1);

namespace Capell\Core\Support\Renderables;

use Illuminate\Database\Eloquent\Model;

final readonly class RenderableViewDataContext
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $dynamicData
     */
    public function __construct(
        public Model $asset,
        public Model $translation,
        public array $meta,
        public array $dynamicData,
        public string $renderKey,
    ) {}
}
