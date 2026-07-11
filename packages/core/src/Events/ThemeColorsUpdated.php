<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Theme;

class ThemeColorsUpdated
{
    public function __construct(public readonly Theme $theme) {}
}
