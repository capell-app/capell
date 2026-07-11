<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class ProofSectionData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{quote?: string, name?: string, role?: string, logo?: string, metric?: string, image?: string, publishedAt?: string, publishedDate?: string, author?: string, type?: string, meta?: array<int, string>}>  $items
     */
    public function __construct(
        public string $heading,
        public ?string $summary = null,
        public array $items = [],
    ) {}

    public function key(): string
    {
        return 'proof';
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
