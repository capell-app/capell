<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

/**
 * A line diff of two values, for showing what a SiteSpec would change. HTML is
 * broken into one line per tag boundary first, so a changed paragraph shows as
 * that paragraph rather than the whole page body.
 */
final class SiteSpecTextDiff
{
    /** Above this many lines on either side, the diff shows the whole old and new value instead of matching lines. */
    private const int MAX_MATCHED_LINES = 500;

    /** @return list<string> Lines prefixed with `- ` (removed), `+ ` (added) or `  ` (unchanged). */
    public static function lines(?string $before, ?string $after): array
    {
        $old = self::split($before);
        $new = self::split($after);

        if (count($old) > self::MAX_MATCHED_LINES || count($new) > self::MAX_MATCHED_LINES) {
            return [...array_map(static fn (string $line): string => '- ' . $line, $old), ...array_map(static fn (string $line): string => '+ ' . $line, $new)];
        }

        $oldCount = count($old);
        $newCount = count($new);

        // Longest common subsequence, filled from the end so the walk below runs forwards.
        $lengths = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;

        while ($i < $oldCount && $j < $newCount) {
            if ($old[$i] === $new[$j]) {
                $lines[] = '  ' . $old[$i];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $lines[] = '- ' . $old[$i];
                $i++;
            } else {
                $lines[] = '+ ' . $new[$j];
                $j++;
            }
        }

        for (; $i < $oldCount; $i++) {
            $lines[] = '- ' . $old[$i];
        }

        for (; $j < $newCount; $j++) {
            $lines[] = '+ ' . $new[$j];
        }

        return $lines;
    }

    /** @return list<string> */
    private static function split(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return preg_split('/\R/', (string) preg_replace('/>\s*</', ">\n<", $value)) ?: [$value];
    }
}
