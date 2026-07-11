<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Spatie\LaravelData\Data;

class MakerDefinitionData extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $group,
        public string $icon,
        public bool $supportsDatabaseWrites,
        public bool $supportsPhpWrites,
    ) {}
}
