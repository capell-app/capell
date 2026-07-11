<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

/**
 * Holds the set of hosts that must never be handled by the frontend catch-all
 * route. The Filament admin domain is the primary case: when admin runs on its
 * own domain with an empty path, request paths such as "pages/125/edit" carry
 * no reserved prefix, so the path-based defences cannot exclude them. Reserving
 * the host stops those requests before page resolution runs.
 */
final class ReservedFrontendDomainRegistry
{
    /** @var array<string, true> */
    private array $domains = [];

    public function reserve(string $domain): void
    {
        $domain = $this->normalize($domain);

        if ($domain === '') {
            return;
        }

        $this->domains[$domain] = true;
    }

    public function isReserved(string $host): bool
    {
        $host = $this->normalize($host);

        if ($host === '') {
            return false;
        }

        return isset($this->domains[$host]);
    }

    /**
     * @return array<int, string>
     */
    public function reservedDomains(): array
    {
        return array_keys($this->domains);
    }

    private function normalize(string $host): string
    {
        $host = strtolower(trim($host));

        // Drop any port component so "admin.test:8080" matches "admin.test".
        $portPosition = strpos($host, ':');

        if ($portPosition !== false) {
            $host = substr($host, 0, $portPosition);
        }

        return trim($host, '.');
    }
}
