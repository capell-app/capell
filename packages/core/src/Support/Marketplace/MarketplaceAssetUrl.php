<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

final class MarketplaceAssetUrl
{
    public static function toUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = trim($path);

        if (str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, 'data:image/')
        ) {
            return $path;
        }

        $webUrl = self::webUrl();

        return $webUrl === null ? null : $webUrl . '/' . ltrim($path, '/');
    }

    public static function webUrl(): ?string
    {
        $configured = config('capell-marketplace.marketplace.web_url');
        $fallback = config('capell.marketplace_web_url', 'https://capell.app');
        $webUrl = is_string($configured) && trim($configured) !== ''
            ? $configured
            : (is_string($fallback) ? $fallback : null);

        if ($webUrl === null || trim($webUrl) === '') {
            return null;
        }

        return rtrim(trim($webUrl), '/');
    }
}
