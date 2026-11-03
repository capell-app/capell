<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;

/**
 * A page of publicly readable results from a bounded scan. It offers only
 * next and previous links: the next link carries the scan's sealed cursor
 * token, and a page reached through a cursor has no previous link.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Paginator<TKey, TValue>
 *
 * @experimental
 */
final class PublicReadPaginator extends Paginator
{
    /**
     * @param  Collection<TKey, TValue>|array<TKey, TValue>  $items
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        Collection|array $items,
        int $perPage,
        int $currentPage,
        array $options,
        private readonly ?string $nextToken,
        private readonly string $cursorName,
        private readonly bool $fromCursor,
    ) {
        parent::__construct($items, $perPage, $currentPage, $options);

        $this->hasMore = $nextToken !== null;
    }

    #[Override]
    public function nextPageUrl(): ?string
    {
        if ($this->nextToken === null) {
            return null;
        }

        $query = $this->query;
        self::forget($query, $this->pageName);
        parse_str($this->cursorName . '=' . rawurlencode($this->nextToken), $cursor);
        $query = array_replace_recursive($query, $cursor);

        $path = (string) $this->path();

        return $path . (str_contains($path, '?') ? '&' : '?') . Arr::query($query) . $this->buildFragment();
    }

    #[Override]
    public function previousPageUrl(): ?string
    {
        return $this->fromCursor ? null : parent::previousPageUrl();
    }

    /**
     * @param  array<array-key, mixed>  $query
     */
    private static function forget(array &$query, string $name): void
    {
        parse_str($name . '=1', $parsed);
        $key = array_key_first($parsed);

        if (! is_string($key) && ! is_int($key)) {
            return;
        }

        if (is_array($parsed[$key]) && is_array($query[$key] ?? null)) {
            $inner = array_key_first($parsed[$key]);
            unset($query[$key][$inner]);

            return;
        }

        unset($query[$key]);
    }
}
