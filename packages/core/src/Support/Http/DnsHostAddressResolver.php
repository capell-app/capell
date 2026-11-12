<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Override;

/**
 * Resolves A and AAAA records with the system resolver.
 */
final readonly class DnsHostAddressResolver implements ResolvesHostAddresses
{
    /** @return list<string> */
    #[Override]
    public function resolve(string $host): array
    {
        $addresses = [];
        $ipv4 = gethostbynamel($host);

        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        $ipv6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
