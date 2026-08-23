<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Widgets;

/**
 * A single resolved block-picker entry, ready for rendering: Filament block
 * identity plus fallback-safe presentation fields and the pre-built
 * `wire:click` action string used to add the block, unchanged from the
 * existing picker behaviour.
 */
final class BlockPickerItemViewData
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $category,
        public readonly ?string $icon,
        public readonly string $searchHaystack,
        public readonly string $wireClickAction,
    ) {}
}
