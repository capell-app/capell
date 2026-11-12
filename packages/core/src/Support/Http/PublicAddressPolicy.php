<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The one answer to "may Capell open a connection to this address?".
 *
 * Every outbound guard (link checks, catalogue fetches, webhooks, purgers, importers)
 * must ask this policy instead of `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
 * PHP's flags miss carrier-grade NAT (100.64.0.0/10), benchmarking (198.18.0.0/15), the
 * IETF protocol block (192.0.0.0/24), NAT64 prefixes and the IPv6 transition formats
 * (IPv4-mapped, IPv4-compatible, 6to4, Teredo) that can carry a private IPv4 address
 * inside an address PHP considers public.
 *
 * The policy fails closed: anything that is not a plain, globally routable unicast
 * address is refused, including IPv6 zone indexes and transition formats whose embedded
 * IPv4 address happens to be public. A public service never needs those forms.
 */
final readonly class PublicAddressPolicy
{
    /**
     * Special-purpose ranges (RFC 6890 and the IANA special-purpose registries) that are
     * never a public destination.
     *
     * @var list<string>
     */
    public const array NON_PUBLIC_RANGES = [
        // IPv4
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT shared address space
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link local, including cloud metadata endpoints
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including limited broadcast
        // IPv6
        '::/96',              // unspecified, loopback and IPv4-compatible
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64 well-known prefix
        '64:ff9b:1::/48',     // NAT64 local-use prefix
        '100::/64',           // discard only
        '2001::/23',          // IETF protocol assignments, including Teredo and benchmarking
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4
        '3fff::/20',          // documentation
        '5f00::/16',          // segment routing SIDs
        'fc00::/7',           // unique local
        'fe80::/10',          // link local
        'fec0::/10',          // deprecated site local
        'ff00::/8',           // multicast
    ];

    /**
     * Whether the address is a plain, globally routable unicast IP address.
     *
     * Accepts a bracketed IPv6 literal as it appears in a URL host. Host names,
     * non-canonical IPv4 forms (`0x7f.1`, `2130706433`) and zone indexes return false.
     */
    public function isPublicAddress(string $address): bool
    {
        $address = trim($address);

        if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
            $address = substr($address, 1, -1);
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, self::NON_PUBLIC_RANGES);
    }

    /**
     * Whether every resolved address is public. An empty list is never public, so a host
     * that does not resolve, or resolves to one internal address among public ones, is refused.
     *
     * @param  iterable<mixed>  $addresses
     */
    public function allPublic(iterable $addresses): bool
    {
        $seen = false;

        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                return false;
            }

            $seen = true;
        }

        return $seen;
    }
}
