<?php

declare(strict_types=1);

namespace Capell\Core\Data\Agent;

use Spatie\LaravelData\Data;

final class AgentSearchResultData extends Data
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $snippet,
    ) {}
}
