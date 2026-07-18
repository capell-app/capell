<?php

declare(strict_types=1);

namespace Capell\Core\Support;

final class CapellSiteSpecConstraints
{
    public const int MIN_PAGES = 1;

    public const int MAX_PAGES = 15;

    public const string SLUG_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    public const string HEX_COLOUR_PATTERN = '^#[0-9A-Fa-f]{6}$';

    public const int MAX_SECTION_CONTENT_LENGTH = 20000;

    public const int MAX_TOTAL_CONTENT_LENGTH = 200000;

    public static function validationRegex(string $pattern): string
    {
        return sprintf('regex:/%s/', $pattern);
    }
}
