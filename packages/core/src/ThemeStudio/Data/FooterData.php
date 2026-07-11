<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class FooterData extends Data implements ThemeSection
{
    /**
     * @param  array<int, array{heading: string, links: array<int, array{label: string, url: string}>}>  $columns
     */
    public function __construct(
        public string $brandName,
        public ?string $summary = null,
        public array $columns = [],
    ) {}

    public function key(): string
    {
        return 'footer';
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
