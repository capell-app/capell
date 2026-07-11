<?php

declare(strict_types=1);

namespace Capell\Core\Support;

final class CapellSiteSpecConstraints
{
    public const MIN_PAGES = 1;

    public const MAX_PAGES = 15;

    public const SLUG_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    public const HEX_COLOUR_PATTERN = '^#[0-9A-Fa-f]{6}$';

    public const MAX_SECTION_CONTENT_LENGTH = 20000;

    public const MAX_TOTAL_CONTENT_LENGTH = 200000;

    public static function validationRegex(string $pattern): string
    {
        return sprintf('regex:/%s/', $pattern);
    }
}
