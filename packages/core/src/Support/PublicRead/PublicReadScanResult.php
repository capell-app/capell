<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

/**
 * @template TItem
 *
 * @experimental
 */
final readonly class PublicReadScanResult
{
    /**
     * @param  list<TItem>  $items
     * @param  PublicReadCursor|null  $next  where the next page continues, when there is one
     */
    public function __construct(
        public array $items,
        public ?PublicReadCursor $next,
        public bool $hasMore,
    ) {}
}
