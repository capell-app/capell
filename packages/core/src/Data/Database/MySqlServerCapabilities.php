<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

use Capell\Core\Enums\Database\DatabaseFamily;

final readonly class MySqlServerCapabilities
{
    public function __construct(
        public string $version,
        public DatabaseFamily $family,
        public bool $generatedColumns,
        public bool $storedGeneratedColumns,
        public bool $functionalIndexes,
    ) {}
}
