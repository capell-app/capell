<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support\Licensing;

/**
 * Local and staging sites are free under a Capell site licence and never take one of its sites, so a site on one of
 * these hosts never sends its licence check at all. The same rule runs on capell.app and is published in the licence
 * terms; keep the two lists identical.
 */
final class NonProductionDomain
{
    /** Reserved or private-use suffixes that never resolve to a public production site. */
    private const array RESERVED_SUFFIXES = ['.test', '.localhost', '.local', '.example', '.invalid', '.internal', '.lan', '.home.arpa'];

    /** A subdomain label from this list marks a staging or preview copy, such as staging.example.com. */
    private const array STAGING_LABELS = ['staging', 'stage', 'stg', 'dev', 'development', 'test', 'testing', 'qa', 'uat', 'preview', 'sandbox', 'demo'];

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

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.')) {
                return true;
            }
        }

        // Only subdomain labels count: dev.to and test.com are someone's production site.
        $labels = explode('.', $host);
        foreach (array_slice($labels, 0, max(0, count($labels) - 2)) as $label) {
            foreach (explode('-', $label) as $part) {
                if (in_array($part, self::STAGING_LABELS, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
