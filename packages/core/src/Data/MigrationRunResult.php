<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class MigrationRunResult extends Data
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}
}
