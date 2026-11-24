<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support\Licensing;

/**
 * Local sites (loopback, private addresses and reserved suffixes such as .test) never send their licence check at
 * all. Staging subdomains on public domains are decided by capell.app, which reads the Public Suffix List and answers
 * them as free without recording anything: telling staging.client.co.uk from dev-agency.co.uk needs that list.
 */
final class NonProductionDomain
{
    /** Reserved or private-use suffixes that never resolve to a public production site. */
    private const array RESERVED_SUFFIXES = ['.test', '.localhost', '.local', '.example', '.invalid', '.internal', '.lan', '.home.arpa'];

    public static function is(string $domain): bool
    {
        $host = strtolower(rtrim(trim($domain), '.'));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return true;
        }

        $address = trim($host, '[]');
        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return array_any(self::RESERVED_SUFFIXES, fn (string $suffix): bool => str_ends_with($host, $suffix) || $host === ltrim($suffix, '.'));
    }
}
