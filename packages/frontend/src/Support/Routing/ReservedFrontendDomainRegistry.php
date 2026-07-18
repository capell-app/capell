<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Routing;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/**
 * Holds the set of hosts that must never be handled by the frontend catch-all
 * route. The Filament admin domain is the primary case: when admin runs on its
 * own domain with an empty path, request paths such as "pages/125/edit" carry
 * no reserved prefix, so the path-based defences cannot exclude them. Reserving
 * the host stops those requests before page resolution runs.
 *
 * @extends AbstractKeyedRegistry<true>
 */
final class ReservedFrontendDomainRegistry extends AbstractKeyedRegistry
{
    public function reserve(string $domain): void
    {
        $domain = $this->normalize($domain);

        if ($domain === '') {
            return;
        }

        $this->setItem($domain, true);
    }

    public function isReserved(string $host): bool
    {
        $host = $this->normalize($host);

        if ($host === '') {
            return false;
        }

        return $this->hasItem($host);
    }

    /**
     * @return array<int, string>
     */
    public function reservedDomains(): array
    {
        return array_keys($this->allItems());
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
