<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class FeatureSectionData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{title: string, description: string, icon?: string, image?: string, publishedAt?: string, publishedDate?: string, author?: string, type?: string, meta?: array<int, string>}>  $features
     */
    public function __construct(
        public string $heading,
        public ?string $summary = null,
        public array $features = [],
    ) {}

    public function key(): string
    {
        return 'features';
    }

    public function fallbackKey(): ?string
    {
        return 'content-listing';
    }

    public function toViewData(): array
    {
        return ['section' => $this];
    }
}
