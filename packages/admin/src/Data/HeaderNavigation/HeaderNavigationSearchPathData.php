<?php

declare(strict_types=1);

namespace Capell\Admin\Data\HeaderNavigation;

use Spatie\LaravelData\Data;

final class HeaderNavigationSearchPathData extends Data
{
    /**
     * @param  list<HeaderNavigationPageNodeData>  $nodes
     */
    public function __construct(
        public readonly HeaderNavigationSiteData $site,
        public readonly array $nodes,
        public readonly int $matchId,
    ) {}

    /**
     * @return array{
     *     site: array{id: int, name: string, edit_url: ?string, public_url: ?string},
     *     nodes: list<array<string, mixed>>,
     *     match_id: int,
     *     key: string
     * }
     */
    public function toRecord(): array
    {
        $nodeIds = array_map(
            fn (HeaderNavigationPageNodeData $node): int => $node->id,
            $this->nodes,
        );

        return [
            'site' => $this->site->toRecord(),
            'nodes' => array_map(
                fn (HeaderNavigationPageNodeData $node): array => $node->toRecord(),
                $this->nodes,
            ),
            'match_id' => $this->matchId,
            'key' => $this->site->id . ':' . implode('.', $nodeIds),
        ];
    }
}
