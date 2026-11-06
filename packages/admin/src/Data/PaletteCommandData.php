<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

/**
 * A single entry in the global command palette.
 * Add-on packages contribute entries via CapellAdmin::registerCommand().
 * Register at boot, with visibility resolved for the current actor at render.
 * Destination access must also be authorised by the extension.
 */
class PaletteCommandData extends Data
{
    /** @param  (Closure(Authenticatable): bool)|null  $visible */
    public function __construct(
        /** Unique identifier, e.g. 'capell.create_page'. */
        public string $id,
        /** Human-readable label shown in the palette. */
        public string $label,
        /** Optional description shown below the label. */
        public ?string $description = null,
        /** Heroicon name or BackedEnum icon value. */
        public null|string|BackedEnum $icon = null,
        /** URL to navigate to when the command is selected. */
        public ?string $url = null,
        /** Alpine.js expression executed when the command is selected (alternative to url). */
        public ?string $alpineHandler = null,
        /** Keyboard shortcut hint string, e.g. "Ctrl+N". */
        public ?string $shortcut = null,
        /** Optional group label for sectioning palette results. */
        public ?string $group = null,
        /** Numeric weight for ordering — lower appears first. */
        public int $sort = 50,
        /** Per-render visibility; never exposed in transformed data or queued. */
        #[Hidden]
        public ?Closure $visible = null,
    ) {}
}
