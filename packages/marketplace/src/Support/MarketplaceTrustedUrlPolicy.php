<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Url\ExternalHttpsUrl;

final class MarketplaceTrustedUrlPolicy
{
    public function trusted(?string $url): ?string
    {
        $url = ExternalHttpsUrl::sanitise($url);

        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || ($port !== null && $port !== 443)) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        if (in_array($host, $this->trustedHosts(), true)) {
            return $url;
        }

        // An address is never a first-party name (a bracketed IPv6 literal keeps its brackets).
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        foreach ($this->firstPartyHosts() as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.' . $trusted)) {
                return $url;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function firstPartyHosts(): array
    {
        $configured = config('capell-marketplace.marketplace.trusted_hosts', []);

        return array_values(collect(is_array($configured) ? $configured : [])
            ->filter(fn (mixed $host): bool => is_string($host))
            ->map(fn (string $host): string => strtolower(rtrim(trim($host), '.')))
            ->filter(fn (string $host): bool => $host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false)
            ->unique()
            ->values()
            ->all());
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
