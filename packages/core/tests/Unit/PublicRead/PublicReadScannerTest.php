<?php

declare(strict_types=1);

use Capell\Core\Support\PublicRead\PublicReadCursor;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadPaginator;
use Capell\Core\Support\PublicRead\PublicReadScanner;

/**
 * An ordered source of integer ids read by keyset: each call continues after
 * the last id read.
 *
 * @param  list<int>  $ids
 * @return array{fetch: Closure(array<string, int|string|null>|null, int): array{items: list<int>, positions: list<array<string, int>>, after: array<string, int|string|null>|null, more: bool}, reads: ArrayObject<int, int>}
 */
function publicReadKeysetSource(array &$ids): array
{
    $reads = new ArrayObject;

    return [
        'fetch' => static function (?array $after, int $limit) use (&$ids, $reads): array {
            $from = (int) ($after['i'] ?? -1);
            $items = array_slice(array_values(array_filter($ids, static fn (int $id): bool => $id > $from)), 0, $limit);
            $reads->append(count($items));
            $positions = array_map(static fn (int $id): array => ['i' => $id], $items);

            return ['items' => $items, 'positions' => $positions, 'after' => $positions === [] ? $after : $positions[array_key_last($positions)], 'more' => count($items) === $limit];
        },
        'reads' => $reads,
    ];
}

function publicReadRevision(): void
{
    app()->forgetScopedInstances();
    resolve(PublicReadEligibilityRevision::class)->advance();
}

beforeEach(function (): void {
    publicReadRevision();
});

it('stops at the scan limit and continues after the last entry read', function (): void {
    $ids = range(0, 4999);
    ['fetch' => $fetch, 'reads' => $reads] = publicReadKeysetSource($ids);
    $readable = static fn (array $items): array => array_map(static fn (int $item): bool => $item >= 2500, $items);
    $scanner = new PublicReadScanner;

    $first = $scanner->scan($fetch, $readable, size: 5, page: 1);

    expect($first->items)->toBe([])
        ->and($first->hasMore)->toBeTrue()
        ->and(array_sum($reads->getArrayCopy()))->toBe(PublicReadScanner::MAX_SCANNED);

    $second = $scanner->scan($fetch, $readable, size: 5, page: 1, cursor: PublicReadCursor::open($first->next?->seal('ctx'), 'ctx'));

    expect($second->items)->toBe([2500, 2501, 2502, 2503, 2504]);

    $third = $scanner->scan($fetch, $readable, size: 5, page: 1, cursor: $second->next);

    expect($third->items)->toBe([2505, 2506, 2507, 2508, 2509]);
});

it('never skips a readable entry when earlier entries are deleted between requests', function (): void {
    $ids = range(1, 30);
    ['fetch' => $fetch] = publicReadKeysetSource($ids);
    $all = static fn (array $items): array => array_fill(0, count($items), true);
    $scanner = new PublicReadScanner;

    $first = $scanner->scan($fetch, $all, size: 10, page: 1);
    // Entries already shown are removed before the next request.
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 5));
    $second = $scanner->scan($fetch, $all, size: 10, page: 1, cursor: $first->next);

    expect($first->items)->toBe(range(1, 10))
        ->and($second->items)->toBe(range(11, 20));
});

it('carries the remaining page skip through a cursor', function (): void {
    $ids = range(0, 4999);
    ['fetch' => $fetch] = publicReadKeysetSource($ids);
    $readable = static fn (array $items): array => array_map(static fn (int $item): bool => $item % 1000 === 0, $items);
    $scanner = new PublicReadScanner;

    // Page three of single entries needs the third readable entry (2000): page skip spans the limit.
    $first = $scanner->scan($fetch, $readable, size: 1, page: 3);
    $second = $scanner->scan($fetch, $readable, size: 1, page: 3, cursor: $first->next);

    expect($first->items)->toBe([])
        ->and($second->items)->toBe([2000]);
});

it('ends without a cursor when the source is exhausted', function (): void {
    $ids = range(0, 11);
    ['fetch' => $fetch] = publicReadKeysetSource($ids);
    $result = (new PublicReadScanner)->scan($fetch, static fn (array $items): array => array_fill(0, count($items), true), size: 5, page: 3);

    expect($result->items)->toBe([10, 11])
        ->and($result->hasMore)->toBeFalse()
        ->and($result->next)->toBeNull();
});

it('seals a cursor that reveals nothing about the source', function (): void {
    $token = (string) new PublicReadCursor(['o' => 4321], 17)->seal('ctx');
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);

    expect($token)->not->toContain('4321')
        ->and((string) $decoded)->not->toContain('4321')
        ->and((string) $decoded)->not->toContain('"s"')
        ->and(PublicReadCursor::open($token, 'ctx'))->toEqual(new PublicReadCursor(['o' => 4321], 17));
});

it('opens no cursor that is malformed, tampered, foreign, expired or from older rules', function (): void {
    $token = (string) new PublicReadCursor(['i' => 99], 0)->seal('search:alpha');
    $tampered = substr($token, 0, -4) . (str_ends_with($token, 'AAAA') ? 'BBBB' : 'AAAA');

    expect(PublicReadCursor::open('not a cursor', 'search:alpha'))->toBeNull()
        ->and(PublicReadCursor::open(base64_encode('{"v":2,"c":"search:alpha","p":{"i":1},"s":0}'), 'search:alpha'))->toBeNull()
        ->and(PublicReadCursor::open($tampered, 'search:alpha'))->toBeNull()
        ->and(PublicReadCursor::open($token, 'search:beta'))->toBeNull()
        ->and(PublicReadCursor::open($token, 'search:alpha'))->not->toBeNull();

    $this->travel(PublicReadCursor::TTL_SECONDS + 1)->seconds();

    expect(PublicReadCursor::open($token, 'search:alpha'))->toBeNull();

    $this->travelBack();
    $fresh = (string) new PublicReadCursor(['i' => 99], 0)->seal('search:alpha');
    publicReadRevision();

    expect(PublicReadCursor::open($fresh, 'search:alpha'))->toBeNull();
});

it('links to the next page with the sealed token and offers no previous link from a cursor', function (): void {
    $token = (string) new PublicReadCursor(['i' => 40], 0)->seal('ctx');
    $paginator = new PublicReadPaginator(
        items: [1, 2],
        perPage: 2,
        currentPage: 3,
        options: ['path' => 'https://example.test/agent/v1/pages', 'pageName' => 'page[number]'],
        nextToken: $token,
        cursorName: 'page[cursor]',
        fromCursor: true,
    );
    $paginator->appends(['set' => 'catalogue', 'page' => ['number' => 3, 'size' => 2]]);

    $next = (string) $paginator->nextPageUrl();
    parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

    expect($paginator->hasMorePages())->toBeTrue()
        ->and($paginator->previousPageUrl())->toBeNull()
        ->and($query['set'] ?? null)->toBe('catalogue')
        ->and($query['page'] ?? null)->toBe(['size' => '2', 'cursor' => $token]);
});

it('follows a source whose pages come back short, and resumes inside a page', function (): void {
    // Pages of ten positions; page one returns only four entries, page two none, page three ten.
    $pages = [1 => [1, 2, 3, 4], 2 => [], 3 => range(21, 30)];
    $fetch = static function (?array $after, int $limit) use ($pages): array {
        $page = (int) ($after['p'] ?? 1);
        $start = (int) ($after['i'] ?? 0);
        $items = $pages[$page] ?? [];
        $slice = array_slice($items, $start, $limit);
        $positions = array_map(static fn (int $index): array => ['p' => $page, 'i' => $start + $index + 1], array_keys($slice));

        if ($start + count($slice) < count($items)) {
            return ['items' => $slice, 'positions' => $positions, 'after' => $positions[count($positions) - 1] ?? $after, 'more' => true];
        }

        return ['items' => $slice, 'positions' => $positions, 'after' => ['p' => $page + 1, 'i' => 0], 'more' => $page < 3, 'scanned' => 10 - $start];
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
