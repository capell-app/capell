<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

/**
 * A host resolver that can give up at a deadline.
 *
 * The system resolver has no timeout of its own, so a stalled DNS server can hold
 * a request far longer than its connect and request timeouts allow. Guarded
 * outbound requests resolve through this method when the bound resolver offers
 * it, with the policy's connect timeout as the deadline.
 */
interface ResolvesHostAddressesWithin extends ResolvesHostAddresses
{
    /**
     * @return list<string> every A and AAAA address for the host; empty when it does not resolve before the deadline
     */
    public function resolveWithin(string $host, float $seconds): array;
}
