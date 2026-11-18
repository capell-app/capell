<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;

/**
 * Writes a SiteSpec as JSON that is the same, byte for byte, every time the same
 * content is written: object keys are sorted, lists keep their order, and the
 * layout is fixed. Two exports of an unchanged site are identical, so a spec kept
 * in Git only shows a diff when the site changed.
 */
final class SiteSpecJson
{
    public static function encode(CapellSiteSpecData $spec): string
    {
        $payload = self::sortKeys($spec->toArray());

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Sorts every object's keys, at every depth, and leaves lists in their order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function sortKeys(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::sortKeys($nested);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
