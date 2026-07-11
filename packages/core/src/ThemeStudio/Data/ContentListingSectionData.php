<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class ContentListingSectionData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{title: string, summary?: string, url?: string, image?: string, publishedAt?: string, publishedDate?: string, author?: string, type?: string, meta?: array<int, string>}>  $items
     */
    public function __construct(
        public string $heading,
        public ?string $summary = null,
        public array $items = [],
        public ?string $variant = null,
    ) {}

    public function key(): string
    {
        return 'content-listing';
    }

    public function fallbackKey(): ?string
    {
        return null;
    }

    public function toViewData(): array
    {
        return [
            'section' => $this,
            'heading' => $this->heading,
            'summary' => $this->summary,
            'items' => $this->items,
            'variant' => $this->variant,
        ];
    }
}
