<?php

declare(strict_types=1);

namespace Capell\Core\Data\Agent;

use Spatie\LaravelData\Data;

final class AgentPageReadData extends Data
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public string $url,
        public string $title,
        public array $properties,
    ) {}
}
