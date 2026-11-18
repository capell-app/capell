<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class ThemeNotFoundException extends Exception
{
    public static function unresolvedForRequest(): self
    {
        return new self('No theme could be resolved for the frontend request. Register a theme with ThemeRegistry::register(), assign one to the site or layout, or set capell-frontend.foundation_theme to a theme key that exists.');
    }
}
