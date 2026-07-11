<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class HeroSectionData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{label: string, url: string, style?: string}>  $actions
     */
    public function __construct(
        public string $heading,
        public ?string $eyebrow = null,
        public ?string $summary = null,
        public array $actions = [],
        public ?string $mediaUrl = null,
        public ?string $mediaAlt = null,
    ) {}

    public function key(): string
    {
        return 'hero';
    }

    public function fallbackKey(): ?string
    {
        return null;
    }

    public function toViewData(): array
    {
        return ['section' => $this];
    }
}
