<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

final class MarketplaceTrustedUrlPolicy
{
    public function trusted(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || strtolower($scheme) !== 'https' || ! is_string($host) || $host === '') {
            return null;
        }

        return in_array(strtolower($host), $this->trustedHosts(), true) ? $url : null;
    }

    /** @return list<string> */
    private function trustedHosts(): array
    {
        return array_values(collect([
            config('capell-marketplace.marketplace.web_url'),
            config('capell.marketplace_web_url'),
            config('capell-marketplace.marketplace.base_url'),
        ])
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): mixed => parse_url($url, PHP_URL_HOST))
            ->filter(fn (mixed $host): bool => is_string($host))
            ->map(fn (string $host): string => strtolower($host))
            ->filter(fn (string $host): bool => $host !== '')
            ->unique()
            ->values()
            ->all());
    }
}
