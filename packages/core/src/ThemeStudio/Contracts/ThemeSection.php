<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
interface ThemeSection
{
    public function key(): string;

    public function fallbackKey(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function toViewData(): array;
}
