<?php

declare(strict_types=1);

namespace Capell\Admin\Data\HeaderNavigation;

use Spatie\LaravelData\Data;

final class HeaderNavigationSearchResultsData extends Data
{
    /**
     * @param  list<HeaderNavigationSearchPathData>  $paths
     */
    public function __construct(
        public readonly array $paths,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $hasMore,
    ) {}

    /**
     * @return array{
     *     paths: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     next_page: ?int
     * }
     */
    public function toRecord(): array
    {
        return [
            'paths' => array_map(
                fn (HeaderNavigationSearchPathData $path): array => $path->toRecord(),
                $this->paths,
            ),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $this->hasMore,
            'next_page' => $this->hasMore ? $this->page + 1 : null,
        ];
    }
}
