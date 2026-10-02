<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ResolveRenderingStrategyAction
{
    use AsFake;
    use AsObject;

    public function handle(?Pageable $page): RenderingStrategyEnum
    {
        // Request loaders eager-load this relation; only standalone pages need the fallback.
        if ($page instanceof Model && ! $page->relationLoaded('blueprint') && method_exists($page, 'blueprint')) {
            $page->loadMissing('blueprint');
        }

        $blueprint = $page instanceof Model && $page->relationLoaded('blueprint') ? $page->blueprint : null;

        if ($blueprint?->is_livewire === true) {
            return RenderingStrategyEnum::FullLivewire;
        }

        return RenderingStrategyEnum::tryFrom((string) ($page?->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::tryFrom((string) ($blueprint?->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::BladeOnly;
    }
}
