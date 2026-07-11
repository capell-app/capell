<?php

declare(strict_types=1);

namespace Capell\Admin\Data\HeaderNavigation;

use Spatie\LaravelData\Data;

final class HeaderNavigationBranchData extends Data
{
    /**
     * @param  list<HeaderNavigationPageNodeData>  $nodes
     */
    public function __construct(
        public readonly array $nodes,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $hasMore,
    ) {}

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     next_page: ?int
     * }
     */
    public function toRecord(): array
    {
        return [
            'items' => array_map(
                fn (HeaderNavigationPageNodeData $node): array => $node->toRecord(),
                $this->nodes,
            ),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
            'next_page' => $this->hasMore ? $this->page + 1 : null,
        ];
    }
}
