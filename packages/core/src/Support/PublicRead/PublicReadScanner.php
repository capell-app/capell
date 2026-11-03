<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Closure;

/**
 * Pages through an ordered source while keeping only publicly readable
 * entries, reading no more than the requested page needs.
 *
 * A request reads at most MAX_SCANNED source entries. When that limit is
 * reached before the page is full, the entries found so far are returned with
 * a cursor that continues after the last entry read. Restricted entries can
 * never make a request unbounded, and no total is ever computed: the only
 * thing a caller learns about the entries it cannot read is that more may
 * follow.
 *
 * The source continues from a position (its own keyset, such as the sort value
 * and id of the last entry read), so an entry deleted or restricted between
 * requests never shifts the read.
 *
 * @experimental
 */
final class PublicReadScanner
{
    public const int MAX_SCANNED = 2000;

    /**
     * The furthest position a source read by offset or page is asked for.
     * Deeper reads would make the source itself do unbounded work.
     */
    public const int MAX_POSITION = 10000;

    private const int BATCH = 200;

    /**
     * $fetch returns up to $limit entries after $after (null: from the start)
     * in source order, the position just after each entry, the position to
     * continue from after the whole call, and whether more may follow. A
     * source read by pages that may come back short reports how many source
     * positions the call consumed ('scanned').
     *
     * @template TItem
     *
     * @param  Closure(array<string, int|string|null>|null $after, int $limit): array{items: list<TItem>, positions: list<array<string, int|string|null>>, after: array<string, int|string|null>|null, more: bool, scanned?: int}  $fetch
     * @param  Closure(list<TItem>): list<bool>  $readable  one decision per entry, in order
     * @return PublicReadScanResult<TItem>
     */
    public function scan(Closure $fetch, Closure $readable, int $size, int $page, ?PublicReadCursor $cursor = null): PublicReadScanResult
    {
        $size = max(1, $size);
        $after = $cursor?->position;
        $skip = $cursor instanceof PublicReadCursor ? $cursor->skip : (max(1, $page) - 1) * $size;
        $found = [];
        $scanned = 0;

        while ($scanned < self::MAX_SCANNED) {
            $batch = $fetch($after, min(self::BATCH, self::MAX_SCANNED - $scanned));
            $items = $batch['items'];
            $decisions = $items === [] ? [] : $readable($items);

            foreach ($items as $position => $item) {
                if (($decisions[$position] ?? false) !== true) {
                    continue;
                }

                if ($skip > 0) {
                    $skip--;

                    continue;
                }

                if (count($found) === $size) {
                    // Another readable entry exists: continue just before it next time.
                    return new PublicReadScanResult($found, new PublicReadCursor($position === 0 ? $after : $batch['positions'][$position - 1]), true);
                }

                $found[] = $item;
            }

            if (! $batch['more']) {
                return new PublicReadScanResult($found, null, false);
            }

            $scanned += max(1, $batch['scanned'] ?? count($items));
            $after = $batch['after'];
        }

        // The read limit was reached before the page filled.
        return new PublicReadScanResult($found, new PublicReadCursor($after, $skip), true);
    }
}
