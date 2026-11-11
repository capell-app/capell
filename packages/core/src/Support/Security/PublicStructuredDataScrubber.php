<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

/**
 * Applies the public output leak policy to structured data that builders emit
 * outside the sanitised page body: JSON-LD graphs and head meta values.
 *
 * Extensions contribute these values, so the policy runs at the point they are
 * assembled rather than trusting each contributor. A key the policy names, or a
 * string value that carries authoring or credential markers, is dropped; the rest
 * of the node is kept.
 */
final readonly class PublicStructuredDataScrubber
{
    public function __construct(private PublicOutputLeakPolicy $policy) {}

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function scrubGraph(array $value): array
    {
        $scrubbed = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isBlockedKey($key)) {
                continue;
            }

            if (is_array($item)) {
                $scrubbed[$key] = $this->scrubGraph($item);

                continue;
            }

            if (is_string($item) && $this->isLeakingValue($item)) {
                continue;
            }

            $scrubbed[$key] = $item;
        }

        return $scrubbed;
    }

    /**
     * @param  array<string, string>  $meta
     * @return array<string, string>
     */
    public function scrubMeta(array $meta): array
    {
        return array_filter(
            $meta,
            fn (string $value, string $key): bool => ! $this->isBlockedKey($key) && ! $this->isLeakingValue($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function isBlockedKey(string $key): bool
    {
        $normalised = strtolower(str_replace('-', '_', $key));

        return in_array($normalised, $this->policy->authoringJsonKeys(), true)
            || in_array($normalised, $this->policy->authoringSignedUrlJsonKeys(), true)
            || in_array($normalised, $this->policy->blockedPublicKeys(), true);
    }

    private function isLeakingValue(string $value): bool
    {
        return array_any($this->policy->blockedPublicValuePatterns(), fn (string $pattern): bool => preg_match($pattern, $value) === 1);
    }
}
