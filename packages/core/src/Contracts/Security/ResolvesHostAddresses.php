<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

/**
 * Resolves a host name to the IP addresses an outbound connection would use.
 *
 * Guarded outbound requests resolve each host once through this contract, check
 * every returned address against the public-address policy and pin the connection
 * to one of them, so a second DNS answer cannot redirect the request.
 */
interface ResolvesHostAddresses
{
    /**
     * @return list<string> every A and AAAA address for the host; empty when it does not resolve
     */
    public function resolve(string $host): array;
}
