<?php

declare(strict_types=1);

namespace Capell\Core\Support;

final class CapellSiteSpecConstraints
{
    public const int MIN_PAGES = 1;

    public const int MAX_PAGES = 15;

    public const int MAX_NAVIGATIONS = 5;

    public const int MAX_EXTENSIONS = 25;

    public const int MAX_MEDIA_IMAGES = 15;

    public const int MAX_MEDIA_FILE_BYTES = 5_000_000;

    public const int MAX_MEDIA_TOTAL_BYTES = 25_000_000;

    public const int MAX_REMOTE_URL_LENGTH = 2048;

    public const string SLUG_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*$';

    public const string HEX_COLOUR_PATTERN = '^#[0-9A-Fa-f]{6}$';

    public const string COMPOSER_PACKAGE_PATTERN = '^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$';

    public const int MAX_SECTION_CONTENT_LENGTH = 20000;

    public const int MAX_TOTAL_CONTENT_LENGTH = 200000;

    public static function validationRegex(string $pattern): string
    {
        return sprintf('regex:/%s/', $pattern);
    }
}
