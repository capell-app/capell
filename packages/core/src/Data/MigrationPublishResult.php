<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

class MigrationPublishResult extends Data
{
    public function __construct(
        public readonly bool $schemaPublished,
        public readonly bool $settingsPublished,
        public readonly string $output,
    ) {}
}
