<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;

/**
 * Extends the server-side theme payload before any Blade renderer receives it.
 *
 * @internal Extension point for optional theme packages.
 */
interface ThemeSectionPayloadContributor
{
    public const string TAG = 'capell.frontend.theme-section-payload-contributor';

    public function contribute(ThemeSection $section): ThemeSection;
}
