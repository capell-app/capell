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

        if (str_starts_with($lowerUrl, '//')) {
            return null;
        }

        return array_any(
            self::ALLOWED_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($lowerUrl, $prefix),
        ) ? $url : null;
    }
}
