<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
interface SectionRenderer
{
    public function themeKey(): string;

    public function sectionKey(): string;

    public function render(ThemeSection $section): string;
}
