<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Spatie\LaravelData\Data;

final class AgentPageQueryData extends Data
{
    /** @param array<string, array<string, mixed>> $filters */
    public function __construct(
        public string $set,
        public array $filters = [],
        public ?string $sort = null,
        public int $size = 20,
        public int $page = 1,
        public ?int $languageId = null,
        public bool $publicUrlRequired = false,
    ) {}
}
