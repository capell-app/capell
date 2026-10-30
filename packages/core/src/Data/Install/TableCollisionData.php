<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class TableCollisionData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $configKey,
        public readonly string $migration,
    ) {}
}
