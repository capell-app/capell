<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

final class InstallSiteUrl
{
    public static function validationError(string $url): ?string
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return (string) __('capell-core::install.site.url_invalid');
        }

        return null;
    }
}
