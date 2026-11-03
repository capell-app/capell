<?php

declare(strict_types=1);

use Capell\Core\Support\PublicRead\PublicReadCursor;
use Capell\Core\Support\PublicRead\PublicReadPaginator;
use Capell\Core\Support\PublicRead\PublicReadScanner;

/**
 * @return array{fetch: Closure(int, int): array{items: list<int>, more: bool}, reads: ArrayObject<int, int>}
 */
function publicReadScanSource(int $count): array
{
    $reads = new ArrayObject;

    return [
        'fetch' => static function (int $offset, int $limit) use ($count, $reads): array {
            $items = $offset >= $count ? [] : range($offset, min($count, $offset + $limit) - 1);
            $reads->append(count($items));

            return ['items' => $items, 'more' => $offset + count($items) < $count];
        },
        'reads' => $reads,
    ];
}

it('stops at the scan limit and continues from a cursor where it stopped', function (): void {
    ['fetch' => $fetch, 'reads' => $reads] = publicReadScanSource(5000);
    $readable = static fn (array $items): array => array_map(static fn (int $item): bool => $item >= 2500, $items);
    $scanner = new PublicReadScanner;

    $first = $scanner->scan($fetch, $readable, size: 5, page: 1);

    expect($first->items)->toBe([])
        ->and($first->hasMore)->toBeTrue()
        ->and($first->next?->offset)->toBe(PublicReadScanner::MAX_SCANNED)
        ->and(array_sum($reads->getArrayCopy()))->toBe(PublicReadScanner::MAX_SCANNED);

    $second = $scanner->scan($fetch, $readable, size: 5, page: 1, cursor: PublicReadCursor::decode($first->next?->encode()));

    expect($second->items)->toBe([2500, 2501, 2502, 2503, 2504])
        ->and($second->next?->offset)->toBe(2505);

    $third = $scanner->scan($fetch, $readable, size: 5, page: 1, cursor: $second->next);

    expect($third->items)->toBe([2505, 2506, 2507, 2508, 2509]);
});

it('carries the remaining page skip through a cursor', function (): void {
    ['fetch' => $fetch] = publicReadScanSource(5000);
    $readable = static fn (array $items): array => array_map(static fn (int $item): bool => $item % 1000 === 0, $items);
    $scanner = new PublicReadScanner;

    // Page three of single entries needs the third readable entry (2000): page skip spans the limit.
    $first = $scanner->scan($fetch, $readable, size: 1, page: 3);
    $second = $scanner->scan($fetch, $readable, size: 1, page: 3, cursor: $first->next);

    expect($first->items)->toBe([])
        ->and($first->next?->skip)->toBe(0)
        ->and($second->items)->toBe([2000]);
});

it('ends without a cursor when the source is exhausted', function (): void {
    ['fetch' => $fetch] = publicReadScanSource(12);
    $result = (new PublicReadScanner)->scan($fetch, static fn (array $items): array => array_fill(0, count($items), true), size: 5, page: 3);

    expect($result->items)->toBe([10, 11])
        ->and($result->hasMore)->toBeFalse()
        ->and($result->next)->toBeNull();
});

it('rejects a malformed cursor', function (string $token): void {
    expect(PublicReadCursor::decode($token))->toBeNull();
})->with(['not base64 !', base64_encode('{"o":-1,"s":0}'), base64_encode('{"o":"1","s":0}'), '']);

it('links to the next page with the cursor and offers no previous link from a cursor', function (): void {
    $paginator = new PublicReadPaginator(
        items: [1, 2],
        perPage: 2,
        currentPage: 3,
        options: ['path' => 'https://example.test/agent/v1/pages', 'pageName' => 'page[number]'],
        nextCursor: new PublicReadCursor(40, 0),
        cursorName: 'page[cursor]',
        fromCursor: true,
    );
    $paginator->appends(['set' => 'catalogue', 'page' => ['number' => 3, 'size' => 2]]);

    $next = (string) $paginator->nextPageUrl();
    parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

    expect($paginator->hasMorePages())->toBeTrue()
        ->and($paginator->previousPageUrl())->toBeNull()
        ->and($query['set'] ?? null)->toBe('catalogue')
        ->and($query['page'] ?? null)->toBe(['size' => '2', 'cursor' => new PublicReadCursor(40, 0)->encode()]);
});

it('follows a source whose pages come back short, and resumes inside a page', function (): void {
    // Pages of ten positions; page one returns only four entries, page two none, page three ten.
    $pages = [1 => [1, 2, 3, 4], 2 => [], 3 => range(21, 30)];
    $fetch = static function (int $offset, int $limit) use ($pages): array {
        $page = intdiv($offset, 10) + 1;
        $start = $offset % 10;
        $items = $pages[$page] ?? [];
        $slice = array_slice($items, $start, $limit);

        if ($start + count($slice) < count($items)) {
            return ['items' => $slice, 'more' => true];
        }

        return ['items' => $slice, 'more' => $page < 3, 'next' => $page * 10, 'scanned' => 10 - $start];
    };
    $all = static fn (array $items): array => array_fill(0, count($items), true);
    $scanner = new PublicReadScanner;

    $first = $scanner->scan($fetch, $all, size: 6, page: 1);
    $second = $scanner->scan($fetch, $all, size: 6, page: 1, cursor: $first->next);

    expect($first->items)->toBe([1, 2, 3, 4, 21, 22])
        ->and($first->hasMore)->toBeTrue()
        ->and($second->items)->toBe([23, 24, 25, 26, 27, 28])
        ->and($scanner->scan($fetch, $all, size: 6, page: 2)->items)->toBe($second->items);
});
