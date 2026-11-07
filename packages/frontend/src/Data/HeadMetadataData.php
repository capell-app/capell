<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

final readonly class HeadMetadataData
{
    /** @param array<string, string> $meta
     * @param  array<int, array<string, mixed>>  $graph
     */
    public function __construct(public array $meta, public array $graph) {}
}
