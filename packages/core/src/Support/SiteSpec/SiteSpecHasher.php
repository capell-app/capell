<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Support\Json\JsonCodec;

/**
 * Hashes a SiteSpec so the same content always gives the same value, whatever
 * order its keys were written in. A site stores the hash of the spec last
 * applied to it.
 */
final class SiteSpecHasher
{
    public static function hash(CapellSiteSpecData $spec): string
    {
        $payload = $spec->toArray();

        // A page without properties hashes exactly as it did before the
        // `properties` field existed, so re-importing an older spec still
        // finds its site instead of building a duplicate.
        foreach ($payload['pages'] ?? [] as $index => $page) {
            if (is_array($page) && ($page['properties'] ?? null) === []) {
                unset($payload['pages'][$index]['properties']);
            }
        }

        $sort = function (array &$value) use (&$sort): void {
            foreach ($value as &$nested) {
                if (is_array($nested)) {
                    $sort($nested);
                }
            }

            unset($nested);
            if (! array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($payload);

        return hash('sha256', JsonCodec::encode($payload));
    }
}
