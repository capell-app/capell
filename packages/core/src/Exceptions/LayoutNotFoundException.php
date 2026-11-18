<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Exception;

class LayoutNotFoundException extends Exception
{
    public static function unresolvedForRequest(): self
    {
        return new self('No layout could be resolved for the frontend request. Assign a layout to the page or site, or register a layout view with LayoutViewRegistry.');
    }
}
