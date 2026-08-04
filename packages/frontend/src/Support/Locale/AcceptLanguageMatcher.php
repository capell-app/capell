<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Locale;

/**
 * RFC 4647 "lookup"-style matching of an Accept-Language header against the
 * language tags a site actually publishes.
 *
 * Two deliberate departures from a naive implementation:
 *
 * - A shared base language counts as a MATCH. An `en-US` visitor landing on an
 *   `en-GB` page reads that page perfectly well; redirecting or bannering them
 *   is noise, so `sameBaseLanguage()` is what the caller checks first.
 * - `Accept-Language: *` carries no preference at all and is reported as empty
 *   rather than as "matches everything".
 */
final class AcceptLanguageMatcher
{
    /**
     * Parse the header into normalised tags ordered by descending q-value.
     *
     * Tags with `q=0` are explicit rejections and are dropped. The wildcard is
     * dropped too — see the class docblock.
     *
     * @return list<string>
     */
    public function preferences(?string $header): array
    {
        $header = mb_trim((string) $header);

        if ($header === '') {
            return [];
        }

        $ranked = [];
        $order = 0;

        foreach (explode(',', $header) as $part) {
            $part = mb_trim($part);

            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $tag = $this->normalise(mb_trim($segments[0]));
            if ($tag === '') {
                continue;
            }

            if ($tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                $parameter = mb_trim($parameter);

                if (! str_starts_with(mb_strtolower($parameter), 'q=')) {
                    continue;
                }

                $quality = (float) mb_substr($parameter, 2);
            }

            if ($quality <= 0.0) {
                continue;
            }

            // Stable sort key: quality first, header order as the tie-breaker.
            $ranked[$tag] ??= [$quality, $order++];
        }

        uasort($ranked, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        return array_keys($ranked);
    }

    /**
     * Does the header express a preference for `$tag` at ANY q-value?
     *
     * "At any q-value" is intentional: a visitor who listed a language at all
     * has stated they read it, and must never be moved away from it.
     */
    public function accepts(?string $header, ?string $tag): bool
    {
        $tag = $this->normalise((string) $tag);

        if ($tag === '') {
            return false;
        }

        return array_any(
            $this->preferences($header),
            fn (string $preference): bool => $this->sameBaseLanguage($preference, $tag),
        );
    }

    /**
     * The first available tag the visitor prefers, in their own priority order.
     *
     * @param  array<int|string, string>  $available  tag keyed however the caller likes
     * @return int|string|null the matching key from `$available`
     */
    public function bestMatch(?string $header, array $available): int|string|null
    {
        foreach ($this->preferences($header) as $preference) {
            foreach ($available as $key => $tag) {
                if ($this->sameBaseLanguage($preference, $tag)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * `fr-CA` matches `fr` and `fr-FR`; `en-US` matches `en-GB`.
     */
    public function sameBaseLanguage(?string $first, ?string $second): bool
    {
        $first = $this->baseLanguage($first);
        $second = $this->baseLanguage($second);

        return $first !== '' && $first === $second;
    }

    public function normalise(?string $tag): string
    {
        return mb_strtolower(str_replace('_', '-', mb_trim((string) $tag)));
    }

    private function baseLanguage(?string $tag): string
    {
        $tag = $this->normalise($tag);
        $separator = mb_strpos($tag, '-');

        return $separator === false ? $tag : mb_substr($tag, 0, $separator);
    }
}
