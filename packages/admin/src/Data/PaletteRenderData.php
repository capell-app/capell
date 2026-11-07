<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

/** Access decisions for one render only; never dehydrated into Livewire state. */
final class PaletteRenderData extends Data
{
    /**
     * @param  array<string, PaletteCommandData>  $commands
     * @param  list<array{sequence?: string, key?: string, action: string, target: string, label: string, url?: string}>  $shortcuts
     */
    public function __construct(
        public array $commands,
        public array $shortcuts,
    ) {}
}
