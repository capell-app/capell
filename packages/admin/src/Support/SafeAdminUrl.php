<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

final class SafeAdminUrl
{
    public static function href(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }
}
