<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Contracts;

use Capell\Core\ThemeStudio\Data\ThemePageData;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
interface ThemePageAdapter
{
    public function currentPage(): ThemePageData;
}
