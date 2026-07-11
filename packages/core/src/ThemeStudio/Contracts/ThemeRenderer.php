<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

use Capell\Core\ThemeStudio\Data\ThemePageData;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering. New themes may skip
 * this and register a definition-only entry with {@see ThemeRegistry::register()}.
 */
interface ThemeRenderer
{
    public function themeKey(): string;

    public function render(ThemePageData $page): string;
}
