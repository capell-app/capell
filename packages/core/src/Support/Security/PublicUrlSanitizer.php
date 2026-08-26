<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

final class PublicUrlSanitizer
{
    /**
     * @var array<int, string>
     */
    private const array ALLOWED_PREFIXES = [
        '/',
        '#',
        'https://',
        'http://',
        'mailto:',
        'tel:',
    ];

    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || str_contains($url, "\0")) {
            return null;
        }

        $lowerUrl = strtolower($url);

        // Browsers treat a leading `/\`, `\/` or `\\` exactly like `//`, so a
        // backslash variant is protocol-relative and navigates off-site. Only
        // the leading pair is normalised; a backslash later in the string is
        // left alone so legitimate paths keep working.
        if (str_starts_with(str_replace('\\', '/', substr($lowerUrl, 0, 2)), '//')) {
            return null;
        }

        return array_any(
            self::ALLOWED_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($lowerUrl, $prefix),
        ) ? $url : null;
    }
}
