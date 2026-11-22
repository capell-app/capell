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

        if (! is_string($host)) {
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
