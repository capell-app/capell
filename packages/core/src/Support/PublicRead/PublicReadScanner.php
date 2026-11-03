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
 * a cursor that continues from the last entry read, so restricted entries can
 * never make a request unbounded and no total is ever computed.
 *
 * @experimental
 */
final class PublicReadScanner
{
    public const int MAX_SCANNED = 2000;

    private const int BATCH = 200;

    /**
     * @template TItem
     *
     * $fetch returns up to $limit entries from $offset in source order and
     * whether more follow. A source whose positions are not contiguous (pages
     * that may come back short) also returns the offset to continue from
     * ('next') and how many source positions the call consumed ('scanned').
     *
     * @param  Closure(int $offset, int $limit): array{items: list<TItem>, more: bool, next?: int, scanned?: int}  $fetch
     * @param  Closure(list<TItem>): list<bool>  $readable  one decision per entry, in order
     * @return PublicReadScanResult<TItem>
     */
    public function scan(Closure $fetch, Closure $readable, int $size, int $page, ?PublicReadCursor $cursor = null): PublicReadScanResult
    {
        $size = max(1, $size);
        $offset = $cursor instanceof PublicReadCursor ? $cursor->offset : 0;
        $skip = $cursor instanceof PublicReadCursor ? $cursor->skip : (max(1, $page) - 1) * $size;
        $found = [];
        $scanned = 0;

        while ($scanned < self::MAX_SCANNED) {
            $batch = $fetch($offset, min(self::BATCH, self::MAX_SCANNED - $scanned));
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
                    // Another readable entry exists: continue from it next time.
                    return new PublicReadScanResult($found, new PublicReadCursor($offset + $position), true);
                }

                $found[] = $item;
            }

            if (! $batch['more']) {
                return new PublicReadScanResult($found, null, false);
            }

            $scanned += max(1, $batch['scanned'] ?? count($items));
            $offset = $batch['next'] ?? $offset + count($items);
        }

        // The read limit was reached before the page filled.
        return new PublicReadScanResult($found, new PublicReadCursor($offset, $skip), true);
    }
}
